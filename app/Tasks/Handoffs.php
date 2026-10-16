<?php

namespace App\Tasks;

use App\Enums\ActorType;
use App\Enums\RunStatus;
use App\Enums\TaskMessageKind;
use App\Enums\TriggerType;
use App\Events\TaskUpdated;
use App\Models\Agent;
use App\Models\AgentHandoff;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agents message each other, like Grok's "Messaged …" (docs/DECISOES.md,
 * "Mensagens entre agentes"). An agent passes a person's request to a
 * colleague: the colleague gets only the brief, never the conversation it
 * came from, and works on it in that person's own conversation with it,
 * where it speaks to the person. When its turn ends, its answer goes back
 * on its own to the agent that passed the request, which tells the person.
 * Both conversations show the hand-off as a row.
 */
final class Handoffs
{
    /** How many agents a request may pass through, so they cannot hand it around forever. */
    public const MAX_DEPTH = 3;

    /** Open hand-offs an agent may have from one thread at a time. */
    public const MAX_OPEN = 3;

    public function __construct(private readonly TaskThread $threads, private readonly OrgChart $chart) {}

    /**
     * Pass the request to the colleague. Returns why it cannot, as a sentence
     * for the agent, instead of a hand-off.
     */
    public function send(AgentRun $run, Agent $from, Agent $to, string $brief): AgentHandoff|string
    {
        $task = $run->task;
        $person = $task?->user;

        if ($task === null || $person === null) {
            return 'só se passa um pedido a um colega numa conversa ou tarefa com uma pessoa; para trabalho sem pessoa usa tasks.create.';
        }

        if ($to->id === $from->id) {
            return 'não te podes mandar mensagens a ti próprio.';
        }

        if (! $to->isActive()) {
            return "{$to->name} está {$to->status->label()} e não pode trabalhar agora.";
        }

        $incoming = $this->incoming($run);

        if (! $this->chart->canDelegate($from, $to)) {
            // Working on a request passed here: the agent that passed it routes the rest.
            return $incoming !== null
                ? "{$to->name} não está abaixo de ti. Não digas que lhe passaste: escreve na tua resposta que esta parte é para {$to->name}, e {$incoming->fromAgent?->name} passa-lha."
                : "{$to->name} não está abaixo de ti nem é a tua chefia no organigrama; passa o pedido ao Chief of Staff.";
        }

        if (! $person->can('run', $to)) {
            return "{$person->name} não tem acesso a {$to->name}, por isso {$to->name} não lhe pode responder. Diz-lhe que peça acesso à chefia, ou dá a tarefa com tasks.create.";
        }

        if ($incoming !== null && $incoming->from_agent_id === $to->id && $incoming->isOpen()) {
            return "não precisas: a tua resposta segue sozinha para {$to->name} quando terminares.";
        }

        $depth = ($incoming->depth ?? 0) + 1;

        if ($depth > self::MAX_DEPTH) {
            return 'este pedido já passou por agentes demais; faz tu o que puderes ou pergunta à pessoa.';
        }

        if (AgentHandoff::query()->where('from_task_id', $task->id)->where('from_agent_id', $from->id)->where('status', AgentHandoff::WORKING)->count() >= self::MAX_OPEN) {
            return 'já tens pedidos demais à espera de colegas nesta conversa; espera pelas respostas.';
        }

        $conversation = $this->threads->conversation($person, $to);

        // All or nothing: a hand-off without its rows would wait forever.
        $handoff = DB::transaction(fn () => $this->open($run, $from, $to, $person, $task, $conversation, $brief, $depth));
        $this->threads->wake($conversation, null, $handoff->toMessage, TriggerType::Agent);

        return $handoff->hand;
    }

    /**
     * @return object{hand: AgentHandoff, toMessage: TaskMessage}
     */
    private function open(AgentRun $run, Agent $from, Agent $to, User $person, Task $task, Task $conversation, string $brief, int $depth): object
    {
        $handoff = AgentHandoff::query()->create([
            'user_id' => $person->id,
            'from_agent_id' => $from->id,
            'to_agent_id' => $to->id,
            'from_task_id' => $task->id,
            'to_task_id' => $conversation->id,
            'from_run_id' => $run->id,
            'brief' => $brief,
            'status' => AgentHandoff::WORKING,
            'depth' => $depth,
        ]);

        AuditLog::record($from, 'agent.handoff', ['handoff_id' => $handoff->id, 'to_agent_id' => $to->id, 'user_id' => $person->id, 'from_task_id' => $task->id, 'to_task_id' => $conversation->id], subject: $task);

        // Here: "Mensagem enviada a …". There: "Mensagem de …", which wakes the colleague once all is saved.
        $this->row($task, $brief, $handoff, $run);
        $message = $this->threads->post($conversation, $from, $brief, TaskMessageKind::Handoff, wake: false, handoff: $handoff);

        return (object) ['hand' => $handoff, 'toMessage' => $message];
    }

    /**
     * A run ended in a conversation that has requests passed to its agent:
     * the answer goes back to each agent that passed one, which is woken to
     * tell its person. A run that stopped without a word leaves them open.
     */
    public function settle(AgentRun $run): void
    {
        if ($run->task_id === null || ! in_array($run->status, [RunStatus::Completed, RunStatus::Failed], true)) {
            return;
        }

        $text = trim((string) ($run->output['text'] ?? ''));

        if ($run->status === RunStatus::Completed && $text === '') {
            return;
        }

        $open = AgentHandoff::query()
            ->where('to_task_id', $run->task_id)
            ->where('to_agent_id', $run->agent_id)
            ->where('status', AgentHandoff::WORKING)
            ->where('created_at', '<=', $run->created_at)
            ->with(['fromTask', 'fromAgent', 'toAgent', 'toTask'])
            ->orderBy('id')
            ->get();

        foreach ($open as $handoff) {
            $failed = $run->status === RunStatus::Failed;
            $answer = $failed ? 'Não consegui terminar: '.Str::limit((string) $run->error, 300) : $text;

            $handoff->forceFill([
                'status' => $failed ? AgentHandoff::FAILED : AgentHandoff::ANSWERED,
                'answer' => $answer,
                'answer_run_id' => $run->id,
                'answered_at' => now(),
            ])->save();

            // There: "Mensagem enviada a …". Back here: "Mensagem de …", which wakes the agent that passed it.
            $this->row($handoff->toTask, $answer, $handoff, $run);
            $this->threads->post($handoff->fromTask, $handoff->toAgent, $answer, TaskMessageKind::Handoff, handoff: $handoff);
        }
    }

    /**
     * The hand-off that woke this run, if a colleague's message did.
     */
    public function incoming(AgentRun $run): ?AgentHandoff
    {
        if ($run->trigger_source_type !== (new TaskMessage)->getMorphClass()) {
            return null;
        }

        $message = TaskMessage::query()->find($run->trigger_source_id);

        return $message !== null && $message->handoff_id !== null && $message->author_type === ActorType::Agent
            ? AgentHandoff::query()->find($message->handoff_id)
            : null;
    }

    /**
     * A hand-off row as the agent of this thread reads it, in its history or
     * as what woke it.
     */
    public function forModel(TaskMessage $message, Task $task, bool $now = false): string
    {
        $handoff = $message->handoff;

        if ($handoff === null) {
            return $message->body;
        }

        $person = $handoff->user->name ?? 'a pessoa';
        $here = $task->id === $handoff->from_task_id;

        // Our own row: what this agent sent.
        if ($message->author_type === ActorType::System) {
            return $here
                ? "Passaste este pedido a {$handoff->toAgent?->name} (agents.message):\n{$message->body}"
                : "A tua resposta seguiu para {$handoff->fromAgent?->name}.";
        }

        if ($here) {
            return "{$handoff->toAgent?->name} respondeu ao pedido que lhe passaste:\n\n{$message->body}"
                .($now ? "\n\n{$handoff->toAgent?->name} já escreveu a {$person} na conversa com ele. Diz a {$person} o que importa, em poucas linhas, sem repetir tudo; se houver uma decisão a tomar, pergunta-lhe." : '');
        }

        return "{$handoff->fromAgent?->name} passou-te um pedido de {$person}:\n\n{$message->body}"
            .($now ? "\n\nEsta é a tua conversa com {$person}: trabalha nisto agora com as tuas ferramentas e escreve-lhe aqui o que fizeste e o resultado. A tua resposta final segue sozinha para {$handoff->fromAgent?->name}."
                ." Não vês a conversa de onde veio o pedido. Não contactas ninguém de fora nem envias emails sem ordem de {$person}." : '');
    }

    /**
     * The row for the screen: who is on the other side, which way the
     * message went, and where the other conversation is, if the viewer may open it.
     *
     * @return array{direction: string, role: string, agent: string|null, agent_id: int|null, status: string, other_task_id: int|null}|null
     */
    public function present(TaskMessage $message, Task $task, User $viewer): ?array
    {
        $handoff = $message->handoff;

        if ($handoff === null) {
            return null;
        }

        $here = $task->id === $handoff->from_task_id;
        $other = $here ? $handoff->toAgent : $handoff->fromAgent;
        $otherTask = $here ? $handoff->toTask : $handoff->fromTask;
        $out = $message->author_type === ActorType::System;

        return [
            'direction' => $out ? 'out' : 'in',
            // The request going to the colleague, or its answer coming back.
            'role' => $out === $here ? 'request' : 'answer',
            'agent' => $other?->name,
            'agent_id' => $other?->id,
            // The request this row sent or answered: still with the colleague, answered, or failed.
            'status' => $handoff->status,
            'other_task_id' => $otherTask !== null && $viewer->can('view', $otherTask) ? $otherTask->id : null,
        ];
    }

    private function row(Task $task, string $body, AgentHandoff $handoff, AgentRun $run): void
    {
        $task->messages()->create([
            'author_type' => ActorType::System,
            'kind' => TaskMessageKind::Handoff,
            'body' => $body,
            'agent_run_id' => $run->id,
            'handoff_id' => $handoff->id,
        ]);

        $task->forceFill(['last_activity_at' => now()])->save();
        TaskUpdated::live($task);
    }
}
