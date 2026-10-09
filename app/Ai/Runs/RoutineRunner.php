<?php

namespace App\Ai\Runs;

use App\Enums\NotificationCategory;
use App\Enums\RunStatus;
use App\Enums\TaskKind;
use App\Enums\TaskMessageKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TriggerType;
use App\Enums\WorkflowStatus;
use App\Enums\WorkflowTrigger;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\User;
use App\Support\Notifier;
use App\Tasks\TaskThread;
use App\Workflows\WorkflowEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Runs a routine (docs/DECISOES.md, "Rotinas e fluxos agendados").
 *
 * A request to an agent goes into the conversation of the person the routine
 * is for, as a platform message the agent answers there; that person reads the
 * answer like any other reply. A routine that starts a flow opens the flow's
 * task with that person. Either way the admins are told: when the flow starts,
 * and when the agent has answered. Approvals, the Chief of Staff, budgets and
 * the absolute ceiling apply as to any other run.
 */
final class RoutineRunner
{
    public function __construct(
        private readonly TaskThread $threads,
        private readonly AgentRunner $runner,
        private readonly WorkflowEngine $engine,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Run it now. Returns what happened, in a line for the admin, or why it did not run.
     *
     * @return array{ok: bool, message: string}
     */
    public function run(AgentRoutine $routine, ?Carbon $now = null): array
    {
        $routine->loadMissing(['agent.reportsTo', 'workflow.agent.reportsTo', 'workflow.fallbackUser', 'owner', 'createdBy']);
        $routine->forceFill(['last_run_at' => $now ?? now()])->save();

        return $routine->workflow_id !== null ? $this->startFlow($routine) : $this->ask($routine);
    }

    /**
     * The person the result is for: the one chosen on the routine, else whoever
     * created it, else the person the agent answers to.
     */
    public function recipient(AgentRoutine $routine, ?Agent $agent): ?User
    {
        $person = $routine->owner ?? $routine->createdBy ?? $agent?->reportsTo;

        return $person?->is_active ? $person : null;
    }

    /**
     * Called by AgentRunner when any run ends: if it was a routine's, the admins hear of it.
     */
    public function delivered(AgentRun $run): void
    {
        $routine = $run->agent_routine_id !== null ? AgentRoutine::query()->find($run->agent_routine_id) : null;

        if ($routine === null) {
            return;
        }

        $person = $run->task?->user;
        $agent = $run->agent->name;
        $text = trim((string) ($run->output['text'] ?? ''));

        [$title, $body, $level] = match ($run->status) {
            RunStatus::Failed => ["A rotina «{$routine->name}» falhou", Str::limit((string) $run->error, 300), 'warning'],
            RunStatus::AwaitingInput => ["A rotina «{$routine->name}» espera uma resposta", Str::limit($text !== '' ? $text : "{$agent} fez perguntas e continua quando lhe responderem.", 300), 'warning'],
            RunStatus::AwaitingApproval => ["A rotina «{$routine->name}» espera aprovação", Str::limit($text !== '' ? $text : "{$agent} precisa de uma aprovação para continuar.", 300), 'warning'],
            default => ["Rotina «{$routine->name}»: {$agent} respondeu", Str::limit($text !== '' ? $text : 'Concluída, sem texto.', 300), 'info'],
        };

        if ($person !== null) {
            $body .= " (na conversa de {$person->name})";
        }

        foreach ($this->admins($person) as $admin) {
            $url = $run->task !== null && $admin->can('view', $run->task) ? "/tasks/{$run->task->id}" : "/settings/routines?routine={$routine->id}";
            $this->notifier->notify($admin, $title, $body, $url, $agent, $level, NotificationCategory::Agent, ['run_id' => $run->id, 'task_id' => $run->task_id]);
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function ask(AgentRoutine $routine): array
    {
        $agent = $routine->agent;

        if ($agent === null || ! $agent->isActive()) {
            return ['ok' => false, 'message' => 'O agente da rotina não está activo.'];
        }

        $remember = function (AgentRun $run) use ($routine): void {
            $run->forceFill(['agent_routine_id' => $routine->id])->save();
            $routine->forceFill(['last_run_id' => $run->id])->save();
        };
        $person = $this->recipient($routine, $agent);

        if ($person === null) {
            $routine->forceFill(['last_task_id' => null])->save();
            $this->runner->dispatch($agent, (string) $routine->prompt, TriggerType::Schedule, source: $routine, created: $remember);

            return ['ok' => true, 'message' => "{$agent->name} começou. Sem pessoa indicada, o resultado fica nas execuções e vai aos admins."];
        }

        $chat = $this->threads->conversation($person, $agent);
        $routine->forceFill(['last_task_id' => $chat->id, 'last_run_id' => null])->save();

        $note = $this->threads->note($chat, $this->brief($routine, $person), kind: TaskMessageKind::Message);
        $run = $this->threads->wake($chat, null, $note, TriggerType::Schedule, $remember);

        return ['ok' => true, 'message' => $run !== null
            ? "{$agent->name} começou. A resposta aparece na conversa de {$person->name}."
            : "{$agent->name} está a meio de outra resposta na conversa de {$person->name}; pega na rotina a seguir."];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function startFlow(AgentRoutine $routine): array
    {
        $workflow = $routine->workflow;
        $agent = $workflow?->agent;

        if ($workflow === null || $workflow->trigger !== WorkflowTrigger::Schedule || $workflow->status !== WorkflowStatus::Active) {
            return ['ok' => false, 'message' => 'O fluxo da rotina não está activo, ou não começa por agenda.'];
        }

        if ($agent === null || ! $agent->isActive()) {
            return ['ok' => false, 'message' => 'O agente do fluxo não está activo.'];
        }

        $person = $routine->owner ?? $workflow->fallbackUser ?? $this->recipient($routine, $agent);
        $when = now()->setTimezone((string) config('agents.schedule_timezone'))->format('d/m');

        $task = $this->threads->open([
            'kind' => TaskKind::Task,
            'title' => Str::limit("{$routine->name} · {$when}", 200, '…'),
            'description' => "Rotina «{$routine->name}» ({$routine->scheduleLabel()}): começa o fluxo «{$workflow->name}»."
                .(filled($routine->prompt) ? "\n\n{$routine->prompt}" : ''),
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Normal,
            'assignee_agent_id' => $agent->id,
            'user_id' => $person?->id,
            'source_type' => $routine->getMorphClass(),
            'source_id' => $routine->id,
        ], $routine->createdBy ?? $agent, start: false);

        $routine->forceFill(['last_task_id' => $task->id, 'last_run_id' => null])->save();
        $this->engine->start($workflow, $task);

        $title = "Rotina «{$routine->name}» abriu {$task->identifier()}";
        $body = "O fluxo «{$workflow->name}» começou com o {$agent->name}.";

        if ($person !== null) {
            $this->notifier->notify($person, $title, $body, "/tasks/{$task->id}", $agent->name, 'info', NotificationCategory::Task, ['task_id' => $task->id]);
        }

        foreach ($this->admins($person) as $admin) {
            $this->notifier->notify($admin, $title, $body.($person !== null ? " A tarefa está com {$person->name}." : ''), "/tasks/{$task->id}", $agent->name, 'info', NotificationCategory::Agent, ['task_id' => $task->id]);
        }

        return ['ok' => true, 'message' => "Fluxo «{$workflow->name}» começado em {$task->identifier()}."];
    }

    private function brief(AgentRoutine $routine, User $person): string
    {
        $by = $routine->createdBy?->name;

        return "Rotina «{$routine->name}» ({$routine->scheduleLabel()})".($by !== null ? ", criada por {$by}" : '').".\n\n"
            .trim((string) $routine->prompt)
            ."\n\nResponde aqui a {$person->name}.";
    }

    /**
     * The company's admins, who always hear of a routine's result.
     *
     * @return Collection<int, User>
     */
    private function admins(?User $except): Collection
    {
        return User::query()->where('is_active', true)->get()
            ->filter(fn (User $user) => $user->canManageTenant() && $user->id !== $except?->id)
            ->values();
    }
}
