<?php

namespace App\Workflows;

use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Enums\WorkflowRunStatus;
use App\Enums\WorkflowStepStatus;
use App\Jobs\ResumeWorkflowStep;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\EmailMessage;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use App\Tasks\TaskThread;
use Illuminate\Support\Str;

/**
 * Walks a flow one block at a time (docs/DECISOES.md, "Fluxos de trabalho":
 * the platform follows the flow, not the agent).
 *
 * The flow's agent works in the task the triage opened, one step at a time:
 * the platform writes the step in the thread and wakes it, and the agent ends
 * the step with workflow.complete_step (with the answer of a condition or the
 * items of a loop). Another agent's step and a person's step are sub-tasks;
 * the flow moves on when they close. A step the agent cannot do goes to the
 * fallback person, and the flow carries on once that person is done.
 *
 * Every entry point reloads the run before changing it and wakes agents last:
 * on a sync queue a woken agent finishes its whole run inside the call.
 */
final class WorkflowEngine
{
    /** How many times an agent is reminded of a step it left unfinished before it goes to a person. */
    public const REMINDERS = 2;

    /** The agent whose run is calling complete_step: its next step is returned inline instead of woken. */
    private ?int $inlineAgentId = null;

    private ?int $inlineTaskId = null;

    /** @var list<string> */
    private array $inline = [];

    public function __construct(private readonly TaskThread $threads) {}

    public function start(Workflow $workflow, Task $task, ?EmailMessage $email = null): WorkflowRun
    {
        $run = WorkflowRun::query()->create([
            'workflow_id' => $workflow->id,
            'task_id' => $task->id,
            'email_message_id' => $email?->id,
            'graph' => $workflow->graph,
            'state' => [],
            'status' => WorkflowRunStatus::Running,
            'started_at' => now(),
        ]);

        $this->threads->note($task, "Esta tarefa segue o fluxo «{$workflow->name}». A plataforma dá um passo de cada vez ao {$workflow->agent?->name}.");

        $graph = $this->graph($run);
        $trigger = $graph->trigger();

        if ($trigger === null) {
            $this->finish($run);
        } else {
            $this->proceed($run, $trigger);
        }

        return $run->refresh();
    }

    /**
     * The agent ended a step with workflow.complete_step. Returns what it should
     * do next when that is again its own step in the same task, so it can carry
     * on in the same run.
     *
     * @param  list<string>  $items
     */
    public function completeStep(WorkflowStep $step, string $outcome, string $summary, ?string $answer = null, array $items = []): ?string
    {
        $run = $step->run()->firstOrFail();

        $this->inlineAgentId = $step->agent_id;
        $this->inlineTaskId = $run->task_id;
        $this->inline = [];

        try {
            if ($outcome === 'blocked') {
                $this->block($step, $summary);
            } else {
                $this->finishStep($step, ['summary' => $summary, 'items' => $items ?: null], $answer);
                $this->afterAgentStep($run->refresh(), $step, $answer, $items);
            }
        } finally {
            $this->inlineAgentId = null;
            $this->inlineTaskId = null;
        }

        return $this->inline === [] ? null : implode("\n\n", $this->inline);
    }

    /**
     * Called when an agent's run in a task ends. A step it left unfinished is
     * put back to it (twice at most) and then goes to a person.
     */
    public function runEnded(AgentRun $agentRun): void
    {
        $step = $this->openAgentStep($agentRun->task_id, $agentRun->agent_id);

        if ($step === null) {
            return;
        }

        if ($agentRun->status === RunStatus::AwaitingApproval) {
            $step->forceFill(['status' => WorkflowStepStatus::WaitingApproval])->save();
            $step->run()->update(['status' => WorkflowRunStatus::Waiting]);

            return;
        }

        if ($agentRun->status === RunStatus::Failed) {
            $this->block($step, 'A execução do agente falhou: '.Str::limit((string) $agentRun->error, 200));

            return;
        }

        if ($step->status !== WorkflowStepStatus::Active) {
            return;
        }

        if ($step->attempts >= self::REMINDERS) {
            $this->block($step, 'O agente terminou várias vezes sem concluir o passo.');

            return;
        }

        $step->forceFill(['attempts' => $step->attempts + 1])->save();
        $this->askAgent($step, reminder: true);
    }

    /**
     * The approvals an agent asked for in a step were decided: the agent picks the step up again.
     */
    public function runSettled(AgentRun $agentRun): void
    {
        $step = WorkflowStep::query()
            ->where('status', WorkflowStepStatus::WaitingApproval)
            ->where('agent_id', $agentRun->agent_id)
            ->whereHas('run', fn ($q) => $q->where('task_id', $agentRun->task_id))
            ->latest('id')
            ->first();

        if ($step === null) {
            return;
        }

        $decided = $agentRun->approvals()->get()->map(fn ($approval) => "«{$approval->action_summary}»: ".$approval->status->label()
            .($approval->decision_note ? " ({$approval->decision_note})" : '')
            .(is_array($approval->execution_result) && isset($approval->execution_result['content']) ? '. Resultado: '.Str::limit((string) $approval->execution_result['content'], 300) : ''))->implode("\n");

        $step->forceFill(['status' => WorkflowStepStatus::Active])->save();
        $step->run()->update(['status' => WorkflowRunStatus::Running]);

        $this->askAgent($step, preface: "As acções que pediste foram decididas:\n{$decided}\n\n");
    }

    /**
     * A task closed. If it is a step's sub-task, the step ends; if it is a run's own task, the run stops.
     */
    public function taskClosed(Task $task): void
    {
        $step = WorkflowStep::query()->where('task_id', $task->id)->whereIn('status', [WorkflowStepStatus::Waiting, WorkflowStepStatus::Active])->latest('id')->first();

        if ($step !== null) {
            $this->subTaskClosed($step, $task);

            return;
        }

        $run = WorkflowRun::query()->where('task_id', $task->id)->whereIn('status', [WorkflowRunStatus::Running, WorkflowRunStatus::Waiting, WorkflowRunStatus::Blocked])->first();

        if ($run !== null && $task->status === TaskStatus::Cancelled) {
            $this->cancel($run, 'A tarefa foi cancelada.');
        }
    }

    /**
     * Whether a task is a step's sub-task (then the engine, not the parent's agent, picks up what comes next).
     */
    public function ownsTask(Task $task): bool
    {
        return WorkflowStep::query()->where('task_id', $task->id)->exists();
    }

    /**
     * A person decided a step in a sub-task: approve or reject, yes or no, or done.
     */
    public function decide(WorkflowStep $step, string $decision, User $by, ?string $note = null): void
    {
        if (! $step->status->isOpen() || $step->task === null) {
            return;
        }

        $step->forceFill(['answer' => $decision, 'output' => [...($step->output ?? []), 'note' => $note, 'decided_by' => $by->name]])->save();

        AuditLog::record($by, 'workflow.decide', ['workflow_step_id' => $step->id, 'decision' => $decision, 'note' => $note], subject: $step->task);

        $rejected = in_array($decision, ['rejected', 'no'], true) && $step->kind === WorkflowGraph::PERSON;
        $this->threads->setStatus($step->task, $rejected ? TaskStatus::Cancelled : TaskStatus::Done, $by, $note);
    }

    /**
     * A wait is over.
     */
    public function resume(WorkflowStep $step): void
    {
        if ($step->status !== WorkflowStepStatus::Waiting || $step->kind !== WorkflowGraph::WAIT) {
            return;
        }

        $run = $step->run()->firstOrFail();

        if (! $run->status->isOpen()) {
            return;
        }

        $this->finishStep($step, ['summary' => 'Espera terminada.']);
        $run->forceFill(['status' => WorkflowRunStatus::Running])->save();
        $this->proceed($run, $step->node_id);
    }

    public function cancel(WorkflowRun $run, string $reason): void
    {
        $run->steps()->whereIn('status', [WorkflowStepStatus::Active, WorkflowStepStatus::WaitingApproval, WorkflowStepStatus::Waiting])->update(['status' => WorkflowStepStatus::Cancelled, 'finished_at' => now()]);
        $run->forceFill(['status' => WorkflowRunStatus::Cancelled, 'finished_at' => now(), 'current_node_id' => null])->save();

        if ($run->task !== null) {
            $this->threads->note($run->task, "O fluxo parou. {$reason}");
        }
    }

    /**
     * Leave a block by one of its ways out: on to the next block, the end of a
     * loop round, or the end of the flow.
     */
    private function proceed(WorkflowRun $run, string $from, ?string $handle = null): void
    {
        $graph = $this->graph($run);
        $next = $graph->next($from, $handle);

        if ($next !== null) {
            $this->enter($run, $next);

            return;
        }

        $loop = $graph->parent($from);

        if ($loop !== null) {
            $this->endRound($run, $loop);

            return;
        }

        $this->finish($run);
    }

    private function enter(WorkflowRun $run, string $node): void
    {
        $graph = $this->graph($run);
        $run->forceFill(['current_node_id' => $node])->save();

        switch ($graph->type($node)) {
            case WorkflowGraph::END:
                $this->finish($run);
                break;

            case WorkflowGraph::AGENT:
            case WorkflowGraph::CONDITION:
                $step = $this->newStep($run, $node, (string) $graph->type($node), $this->runAgentId($run));
                $missing = $this->missing($graph, $node, $step->agent);

                // Nobody has what this step needs: straight to a person, without wasting the agent's turn.
                $missing === null ? $this->askAgent($step) : $this->block($step, $missing);
                break;

            case WorkflowGraph::HANDOFF:
                $this->handOff($run, $node);
                break;

            case WorkflowGraph::LOOP:
                $this->askAgent($this->newStep($run, $node, 'list', $this->runAgentId($run)));
                break;

            case WorkflowGraph::REPEAT:
                $this->setLoop($run, $node, ['round' => 1]);
                $this->enterBody($run, $node);
                break;

            case WorkflowGraph::WAIT:
                $this->wait($run, $node);
                break;

            case WorkflowGraph::APPROVAL:
            case WorkflowGraph::PERSON:
                $this->askPerson($run, $node);
                break;

            default:
                $this->proceed($run, $node);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private function afterAgentStep(WorkflowRun $run, WorkflowStep $step, ?string $answer, array $items): void
    {
        $graph = $this->graph($run);

        match ($step->kind) {
            WorkflowGraph::CONDITION => $this->proceed($run, $step->node_id, $answer === 'yes' ? 'yes' : 'no'),
            'list' => $this->startEach($run, $step->node_id, $items),
            'until' => $answer === 'yes' || (int) ($this->loop($run, $step->node_id)['round'] ?? 1) >= $this->maxRounds($graph, $step->node_id)
                ? $this->exitLoop($run, $step->node_id)
                : $this->nextRound($run, $step->node_id),
            default => $this->proceed($run, $step->node_id),
        };
    }

    /**
     * @param  list<string>  $items
     */
    private function startEach(WorkflowRun $run, string $loop, array $items): void
    {
        $items = array_slice(array_filter(array_map(fn ($item) => Str::limit(trim((string) $item), 490), $items)), 0, $this->maxRounds($this->graph($run), $loop));

        if ($items === []) {
            $this->exitLoop($run, $loop);

            return;
        }

        $this->setLoop($run, $loop, ['items' => $items, 'index' => 0]);
        $this->enterBody($run, $loop);
    }

    private function enterBody(WorkflowRun $run, string $loop): void
    {
        $start = $this->graph($run)->bodyStart($loop);

        if ($start === null) {
            $this->exitLoop($run, $loop);

            return;
        }

        $this->enter($run, $start);
    }

    /**
     * A round of a loop reached its end: the next item, the question of a
     * "repeat until", or out of the loop.
     */
    private function endRound(WorkflowRun $run, string $loop): void
    {
        $graph = $this->graph($run);
        $state = $this->loop($run, $loop);

        if ($graph->type($loop) === WorkflowGraph::REPEAT) {
            $this->askAgent($this->newStep($run, $loop, 'until', $this->runAgentId($run)));

            return;
        }

        $index = (int) ($state['index'] ?? 0) + 1;

        if ($index >= count($state['items'] ?? [])) {
            $this->exitLoop($run, $loop);

            return;
        }

        $this->setLoop($run, $loop, [...$state, 'index' => $index]);
        $this->enterBody($run, $loop);
    }

    private function nextRound(WorkflowRun $run, string $loop): void
    {
        $state = $this->loop($run, $loop);
        $this->setLoop($run, $loop, ['round' => (int) ($state['round'] ?? 1) + 1]);
        $this->enterBody($run, $loop);
    }

    private function exitLoop(WorkflowRun $run, string $loop): void
    {
        $this->setLoop($run, $loop, null);
        $this->proceed($run, $loop);
    }

    /**
     * Write the step in the thread and put the agent to work on it, or hand it
     * back inline when that agent is the one calling complete_step.
     */
    private function askAgent(WorkflowStep $step, bool $reminder = false, string $preface = ''): void
    {
        $run = $step->run()->with(['workflow', 'task'])->firstOrFail();
        $task = $run->task;

        if ($task === null) {
            $this->cancel($run, 'A tarefa do fluxo já não existe.');

            return;
        }

        $run->forceFill(['status' => WorkflowRunStatus::Running])->save();
        $text = $preface.($reminder ? "Ainda falta concluir este passo. Quando acabares chama workflow.complete_step; se não conseguires, chama-o com outcome \"blocked\" e diz porquê.\n\n" : '').$this->instruction($step, $run);

        if ($this->inlineAgentId === $step->agent_id && $this->inlineTaskId === $task->id) {
            $this->threads->note($task, $this->stepTitle($step, $run));
            $this->inline[] = $text;

            return;
        }

        $message = $this->threads->note($task, $text, kind: TaskMessageKind::Message);
        $this->threads->wake($task->refresh(), null, $message, TriggerType::Agent);
    }

    private function instruction(WorkflowStep $step, WorkflowRun $run): string
    {
        $graph = $this->graph($run);
        $node = $step->node_id;
        $label = $graph->label($node);
        $lines = ["Fluxo «{$run->workflow->name}», passo «{$label}»."];

        if ($run->email_message_id !== null) {
            $lines[] = "A origem é o email #{$run->email_message_id} (lê-o com email.read, email_id {$run->email_message_id}).";
        }

        if ($step->item !== null) {
            $lines[] = "Item deste ciclo: {$step->item}.";
        }

        $capability = $graph->data($node, 'capability');
        $skill = $graph->data($node, 'skill');
        $instruction = trim((string) $graph->data($node, 'instruction', ''));

        switch ($step->kind) {
            case WorkflowGraph::CONDITION:
                $lines[] = "Responde sim ou não: {$label}".($instruction !== '' ? "\n{$instruction}" : '');
                if ($capability) {
                    $lines[] = "Decide com a capacidade {$capability}.";
                }
                $lines[] = 'Depois chama workflow.complete_step com answer "yes" ou "no" e o porquê em summary.';
                break;

            case 'list':
                $max = $this->maxRounds($graph, $node);
                $lines[] = 'Lista os itens deste ciclo: '.($graph->data($node, 'items') ?: $label).'.';
                $lines[] = "Chama workflow.complete_step com os itens em items (no máximo {$max}); sem itens, items vazio.";
                break;

            case 'until':
                $round = (int) ($this->loop($run, $node)['round'] ?? 1);
                $lines[] = "Terminou a volta {$round}. Responde sim ou não: ".($graph->data($node, 'until') ?: $label).'?';
                $lines[] = 'Chama workflow.complete_step com answer "yes" (pára o ciclo) ou "no" (mais uma volta).';
                break;

            default:
                $lines[] = $instruction !== '' ? "O que fazer: {$instruction}" : "O que fazer: {$label}.";
                if ($capability) {
                    $lines[] = "Usa a capacidade {$capability}.";
                }
                if ($skill) {
                    $lines[] = "Segue a skill «{$skill}» (carrega-a com skills.load).";
                }
                $lines[] = 'Quando acabares, chama workflow.complete_step com um resumo curto do que fizeste. Se não conseguires (falta capacidade, dados ou permissão), chama-o com outcome "blocked" e diz porquê.';
        }

        $lines[] = 'Não mudes o estado da tarefa: a plataforma fá-lo no fim do fluxo.';

        return implode("\n", $lines);
    }

    private function stepTitle(WorkflowStep $step, WorkflowRun $run): string
    {
        $label = $this->graph($run)->label($step->node_id);

        return match ($step->kind) {
            WorkflowGraph::CONDITION => "Passo do fluxo: responder a «{$label}».",
            'list' => "Passo do fluxo: listar os itens de «{$label}».",
            'until' => "Passo do fluxo: decidir se «{$label}» continua.",
            default => "Passo do fluxo: «{$label}»".($step->item ? " ({$step->item})" : '').'.',
        };
    }

    /**
     * Why the agent cannot do this block with what it has, or null.
     */
    private function missing(WorkflowGraph $graph, string $node, ?Agent $agent): ?string
    {
        $readiness = app(WorkflowReadiness::class)->forNode($graph, $node, $agent);

        return $readiness['state'] === WorkflowReadiness::MISSING ? implode(' ', $readiness['reasons']) : null;
    }

    private function handOff(WorkflowRun $run, string $node): void
    {
        $graph = $this->graph($run);
        $agent = Agent::query()->find((int) $graph->data($node, 'agent_id'));
        $step = $this->newStep($run, $node, WorkflowGraph::HANDOFF, $agent?->id);

        $missing = $agent === null ? 'O agente deste passo já não existe.' : $this->missing($graph, $node, $agent);

        if ($agent === null || $missing !== null) {
            $this->block($step, $missing ?? 'O agente deste passo já não existe.');

            return;
        }

        $parent = $run->task()->firstOrFail();
        $label = $graph->label($node);
        $instruction = trim((string) $graph->data($node, 'instruction', '')) ?: $label;
        $capability = $graph->data($node, 'capability');

        $run->forceFill(['status' => WorkflowRunStatus::Waiting])->save();

        $task = $this->threads->open([
            'kind' => TaskKind::Task,
            'title' => Str::limit($label.($step->item ? " · {$step->item}" : ''), 200, '…'),
            'description' => "Passo do fluxo «{$run->workflow?->name}», pedido por {$parent->identifier()}.\n\n{$instruction}"
                .($step->item ? "\n\nItem: {$step->item}" : '')
                .($capability ? "\n\nCapacidade indicada: {$capability}." : '')
                ."\n\nQuando terminares, marca esta tarefa como feita com o resultado.",
            'status' => TaskStatus::Todo,
            'priority' => $parent->priority,
            'assignee_agent_id' => $agent->id,
            'parent_id' => $parent->id,
            'due_at' => $parent->due_at,
        ], $parent->assigneeAgent ?? $agent, start: false);

        $step->forceFill(['task_id' => $task->id, 'status' => WorkflowStepStatus::Waiting])->save();
        $this->threads->note($parent, "Passo do fluxo «{$label}» passado ao {$agent->name} em {$task->identifier()}.");
        $this->threads->wake($task->refresh(), null, $task, TriggerType::Agent);
    }

    private function askPerson(WorkflowRun $run, string $node): void
    {
        $graph = $this->graph($run);
        $type = (string) $graph->type($node);
        $step = $this->newStep($run, $node, $type, null);
        $person = User::query()->where('is_active', true)->find((int) $graph->data($node, 'user_id')) ?? $this->fallbackPerson($run);

        if ($person === null) {
            $this->cancel($run, "Ninguém pode decidir «{$graph->label($node)}»: escolha uma pessoa no bloco ou a pessoa de recurso do fluxo.");

            return;
        }

        $label = $graph->label($node);
        $instruction = trim((string) $graph->data($node, 'instruction', ''));
        $how = $type === WorkflowGraph::APPROVAL
            ? 'Aprove ou rejeite neste ecrã; o fluxo segue pelo caminho da sua decisão.'
            : 'Quando terminar, marque a tarefa como feita e o fluxo continua.';

        $this->personTask($run, $step, $person, $label, ($instruction !== '' ? "{$instruction}\n\n" : '').$how);
    }

    /**
     * A step the agent could not do goes to the fallback person; the flow goes
     * on from that block once they finish it.
     */
    private function block(WorkflowStep $step, string $reason): void
    {
        $run = $step->run()->with('workflow')->firstOrFail();
        $this->finishStep($step, ['summary' => $reason], status: WorkflowStepStatus::Blocked);

        $graph = $this->graph($run);
        $label = $graph->label($step->node_id);
        $person = $this->fallbackPerson($run);

        if ($person === null) {
            $run->forceFill(['status' => WorkflowRunStatus::Blocked])->save();

            if ($run->task !== null) {
                $this->threads->note($run->task, "O passo «{$label}» ficou bloqueado e o fluxo não tem pessoa de recurso: {$reason}");
            }

            return;
        }

        $fallback = $this->newStep($run, $step->node_id, 'fallback', null, $step->item);
        $fallback->forceFill(['output' => ['blocked_step_id' => $step->id, 'blocked_kind' => $step->kind]])->save();

        $question = match ($step->kind) {
            WorkflowGraph::CONDITION, 'until' => 'Responda Sim ou Não neste ecrã e o fluxo segue por esse caminho.',
            'list' => 'Escreva os itens, um por linha, na conversa desta tarefa e marque-a como feita; ou marque-a feita sem itens para saltar o ciclo.',
            default => 'Faça este passo e marque a tarefa como feita para o fluxo continuar; cancele-a para parar o fluxo.',
        };

        $this->personTask($run, $fallback, $person, "Passo por fazer: {$label}", "O agente não conseguiu este passo: {$reason}\n\n{$question}");
        $run->forceFill(['status' => WorkflowRunStatus::Blocked])->save();
    }

    private function personTask(WorkflowRun $run, WorkflowStep $step, User $person, string $title, string $body): void
    {
        $parent = $run->task()->firstOrFail();
        $run->forceFill(['status' => $step->kind === 'fallback' ? WorkflowRunStatus::Blocked : WorkflowRunStatus::Waiting])->save();

        $task = $this->threads->open([
            'kind' => TaskKind::Task,
            'title' => Str::limit($title.($step->item ? " · {$step->item}" : ''), 200, '…'),
            'description' => "Fluxo «{$run->workflow?->name}», da tarefa {$parent->identifier()} «{$parent->title}».\n\n{$body}",
            'status' => TaskStatus::Todo,
            'priority' => $step->kind === 'fallback' ? TaskPriority::High : $parent->priority,
            'assignee_user_id' => $person->id,
            'user_id' => $person->id,
            'parent_id' => $parent->id,
            'due_at' => $parent->due_at,
        ], $parent->assigneeAgent ?? $person, start: false);

        $step->forceFill(['task_id' => $task->id, 'status' => WorkflowStepStatus::Waiting])->save();
        $this->threads->note($parent, "Passo «{$this->graph($run)->label($step->node_id)}» com {$person->name} em {$task->identifier()}.");
    }

    private function subTaskClosed(WorkflowStep $step, Task $task): void
    {
        $run = $step->run()->firstOrFail();

        if (! $run->status->isOpen()) {
            return;
        }

        $done = $task->status === TaskStatus::Done;
        $last = $task->messages()->whereIn('author_type', ['agent', 'user'])->reorder('id', 'desc')->value('body');
        $summary = Str::limit((string) ($last ?? ''), 1500);

        if ($run->task !== null) {
            $this->threads->note($run->task, "{$task->identifier()} «{$task->title}» ficou «{$task->status->label()}».".($summary !== '' ? "\n\n{$summary}" : ''), kind: TaskMessageKind::Report);
        }

        switch ($step->kind) {
            case WorkflowGraph::APPROVAL:
                $approved = $done && $step->answer !== 'rejected';
                $this->finishStep($step, ['summary' => $summary], $approved ? 'approved' : 'rejected');
                $run->forceFill(['status' => WorkflowRunStatus::Running])->save();
                $this->proceed($run, $step->node_id, $approved ? 'approved' : 'rejected');
                break;

            case 'fallback':
                $this->finishStep($step, ['summary' => $summary], $step->answer);

                if (! $done && ! in_array($step->answer, ['yes', 'no'], true)) {
                    $this->cancel($run, "{$task->identifier()} foi cancelada.");
                    break;
                }

                $run->forceFill(['status' => WorkflowRunStatus::Running])->save();
                $this->afterFallback($run, $step, $summary);
                break;

            default:
                if (! $done && $step->kind === WorkflowGraph::PERSON) {
                    $this->cancel($run, "{$task->identifier()} foi cancelada.");
                    break;
                }

                if (! $done) {
                    $this->block($step, "{$task->identifier()} foi cancelada.");
                    break;
                }

                $this->finishStep($step, ['summary' => $summary]);
                $run->forceFill(['status' => WorkflowRunStatus::Running])->save();
                $this->proceed($run, $step->node_id);
        }
    }

    /**
     * After a person did what the agent could not, continue as if the blocked step had ended.
     */
    private function afterFallback(WorkflowRun $run, WorkflowStep $step, string $summary): void
    {
        $kind = $step->output['blocked_kind'] ?? null;
        $answer = $step->answer === 'yes' ? 'yes' : 'no';

        match ($kind) {
            WorkflowGraph::CONDITION => $this->proceed($run, $step->node_id, $answer),
            'until' => $answer === 'yes' ? $this->exitLoop($run, $step->node_id) : $this->nextRound($run, $step->node_id),
            'list' => $this->startEach($run, $step->node_id, preg_split('/\R/', $summary) ?: []),
            default => $this->proceed($run, $step->node_id),
        };
    }

    private function wait(WorkflowRun $run, string $node): void
    {
        $hours = max(1, min(720, (int) $this->graph($run)->data($node, 'hours', 24)));
        $step = $this->newStep($run, $node, WorkflowGraph::WAIT, null);
        $step->forceFill(['status' => WorkflowStepStatus::Waiting, 'output' => ['until' => now()->addHours($hours)->toIso8601String()]])->save();
        $run->forceFill(['status' => WorkflowRunStatus::Waiting])->save();

        if ($run->task !== null) {
            $this->threads->note($run->task, "O fluxo espera {$hours} h («{$this->graph($run)->label($node)}»).");
        }

        ResumeWorkflowStep::dispatch($run->tenant_id, $step->id)->delay(now()->addHours($hours));
    }

    private function finish(WorkflowRun $run): void
    {
        $run->forceFill(['status' => WorkflowRunStatus::Completed, 'current_node_id' => null, 'finished_at' => now()])->save();
        $task = $run->task()->first();

        if ($task === null || $task->status->isClosed()) {
            return;
        }

        $agent = $task->assigneeAgent;
        $status = $task->user_id !== null || $task->created_by_user_id !== null ? TaskStatus::InReview : TaskStatus::Done;
        $this->threads->setStatus($task, $status, $agent, 'O fluxo chegou ao fim.');
    }

    private function newStep(WorkflowRun $run, string $node, string $kind, ?int $agentId, ?string $item = null): WorkflowStep
    {
        return WorkflowStep::query()->create([
            'workflow_run_id' => $run->id,
            'node_id' => $node,
            'kind' => $kind,
            'status' => WorkflowStepStatus::Active,
            'agent_id' => $agentId,
            'item' => $item ?? $this->currentItem($run, $node),
            'started_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $output
     */
    private function finishStep(WorkflowStep $step, array $output, ?string $answer = null, WorkflowStepStatus $status = WorkflowStepStatus::Done): void
    {
        $step->forceFill([
            'status' => $status,
            'answer' => $answer ?? $step->answer,
            'output' => [...($step->output ?? []), ...array_filter($output, fn ($value) => $value !== null)],
            'finished_at' => now(),
        ])->save();
    }

    private function openAgentStep(?int $taskId, int $agentId): ?WorkflowStep
    {
        if ($taskId === null) {
            return null;
        }

        return WorkflowStep::query()
            ->whereIn('status', [WorkflowStepStatus::Active])
            ->where('agent_id', $agentId)
            ->whereIn('kind', [WorkflowGraph::AGENT, WorkflowGraph::CONDITION, 'list', 'until'])
            ->whereHas('run', fn ($q) => $q->where('task_id', $taskId)->whereIn('status', [WorkflowRunStatus::Running, WorkflowRunStatus::Waiting]))
            ->latest('id')
            ->first();
    }

    private function currentItem(WorkflowRun $run, string $node): ?string
    {
        $graph = $this->graph($run);
        $loop = $graph->parent($node);

        if ($loop === null) {
            return null;
        }

        $state = $this->loop($run, $loop);

        if (isset($state['items'])) {
            return $state['items'][$state['index'] ?? 0] ?? null;
        }

        return isset($state['round']) ? "volta {$state['round']}" : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function loop(WorkflowRun $run, string $loop): array
    {
        return (array) ($run->refresh()->state['loops'][$loop] ?? []);
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    private function setLoop(WorkflowRun $run, string $loop, ?array $value): void
    {
        $run->refresh();
        $state = $run->state ?? [];
        $loops = (array) ($state['loops'] ?? []);

        if ($value === null) {
            unset($loops[$loop]);
        } else {
            $loops[$loop] = $value;
        }

        $run->forceFill(['state' => [...$state, 'loops' => $loops]])->save();
    }

    private function maxRounds(WorkflowGraph $graph, string $loop): int
    {
        return max(1, min(WorkflowGraph::MAX_ITERATIONS, (int) $graph->data($loop, 'max', 5)));
    }

    private function runAgentId(WorkflowRun $run): ?int
    {
        return $run->task()->value('assignee_agent_id') ?? $run->workflow()->value('agent_id');
    }

    private function fallbackPerson(WorkflowRun $run): ?User
    {
        $workflow = $run->workflow()->first();
        $task = $run->task()->first();

        return User::query()->where('is_active', true)->find($workflow?->fallback_user_id)
            ?? User::query()->where('is_active', true)->find($task?->user_id)
            ?? User::query()->where('is_active', true)->find($workflow?->agent?->reports_to_user_id);
    }

    private function graph(WorkflowRun $run): WorkflowGraph
    {
        return new WorkflowGraph($run->graph);
    }
}
