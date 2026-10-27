<?php

namespace App\Ai\Runs;

use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Enums\TaskKind;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What an agent is doing right now: its live runs, each with its conversation
 * or task and the step it is on, by the same rule the sidebar uses to say it
 * is working (AgentRun::working). Shown at the top of the agent's page and,
 * for work elsewhere, in the person's conversation with it (docs/DECISOES.md,
 * "Agente: o que está a fazer" and "Agente ocupado noutro sítio").
 */
class AgentNow
{
    /** How long a run that ended elsewhere stays in the conversation, with its result. */
    public const RECENT_MINUTES = 30;

    /**
     * The live runs and, when idle, the last finished one. Runs on
     * $exceptTask (the conversation already on screen) are left out. With
     * $withRecent, also the runs that ended in the last RECENT_MINUTES, so
     * work seen running in the conversation does not vanish when it ends
     * (docs/DECISOES.md, "Trabalho noutro sítio que terminou").
     *
     * @return array{active: list<array<string, mixed>>, last: array<string, mixed>|null, recent: list<array<string, mixed>>}
     */
    public function for(Agent $agent, User $user, ?Task $exceptTask = null, bool $withLast = true, bool $withRecent = false): array
    {
        $elsewhere = fn ($q) => $q->when($exceptTask !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('task_id')->orWhere('task_id', '!=', $exceptTask->id)));

        $active = $agent->runs()
            ->working()
            ->tap($elsewhere)
            ->with(['agent:id,name', 'requestedBy:id,name', 'task'])
            ->oldest('id')
            ->get();

        $recent = $withRecent
            ? $agent->runs()
                ->whereIn('status', [RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled])
                ->where('finished_at', '>=', now()->subMinutes(self::RECENT_MINUTES))
                ->tap($elsewhere)
                ->with(['agent:id,name', 'requestedBy:id,name', 'task'])
                ->latest('finished_at')
                ->limit(5)
                ->get()
            : collect();

        $last = $withLast && $active->isEmpty()
            ? $agent->runs()->whereNotIn('status', [RunStatus::Queued, RunStatus::Running])->with(['agent:id,name', 'requestedBy:id,name', 'task'])->latest('id')->first()
            : null;

        $present = $this->presenter($active, $user);

        return [
            'active' => $active->map($present)->values()->all(),
            'last' => $last ? $present($last) : null,
            'recent' => $recent->map($present)->values()->all(),
        ];
    }

    /**
     * The agent's work outside this conversation, for its thread
     * (docs/DECISOES.md, "Agente contínuo"): what it did on its own or for
     * others since the conversation began (at most a week, the last 30),
     * oldest first, so the conversation reads as the agent's one timeline.
     * Its other conversations are left out (they belong to whoever is in
     * them, even when an admin may read them); a task the person cannot open
     * keeps its place without its title.
     *
     * @return list<array<string, mixed>>
     */
    public function background(Agent $agent, User $user, Task $conversation): array
    {
        $runs = $agent->runs()
            ->where(fn ($q) => $q->whereNull('task_id')->orWhere('task_id', '!=', $conversation->id))
            ->where('created_at', '>=', max($conversation->created_at, now()->subDays(7)))
            ->with(['agent:id,name', 'requestedBy:id,name', 'task'])
            ->latest('id')
            ->limit(30)
            ->get()
            ->filter(fn (AgentRun $run) => $run->task === null || $run->task->kind !== TaskKind::Chat)
            ->reverse()
            ->values();

        $present = $this->presenter($runs->filter(fn (AgentRun $run) => in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)), $user);

        return $runs->map($present)->values()->all();
    }

    /**
     * How a run is shown, with the step a live one is on.
     *
     * @param  Collection<int, AgentRun>  $live
     * @return \Closure(AgentRun): array<string, mixed>
     */
    private function presenter($live, User $user): \Closure
    {
        $steps = AgentRunStep::query()
            ->whereIn('agent_run_id', $live->pluck('id'))
            ->whereIn('type', [StepType::ToolCall, StepType::Reasoning, StepType::Message, StepType::Approval])
            ->orderByDesc('seq')
            ->get()
            ->unique('agent_run_id')
            ->keyBy('agent_run_id');

        return fn (AgentRun $run) => [
            ...Present::run($run),
            'started_at' => $run->started_at?->toIso8601String(),
            'task' => $this->runTask($run->task, $user),
            'step' => isset($steps[$run->id]) ? [
                'type' => $steps[$run->id]->type->value,
                'tool_name' => $steps[$run->id]->tool_name,
                'at' => $steps[$run->id]->created_at->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * The conversation or task a run belongs to. Someone else's private
     * conversation is named as such, without its title or a link.
     *
     * @return array{id: int|null, ref: string|null, title: string|null, kind: string, visible: bool}|null
     */
    private function runTask(?Task $task, User $user): ?array
    {
        if ($task === null) {
            return null;
        }

        $visible = $user->can('view', $task);

        return [
            'id' => $visible ? $task->id : null,
            'ref' => $visible ? $task->identifier() : null,
            'title' => $visible ? $task->title : null,
            'kind' => $task->kind->value,
            'visible' => $visible,
        ];
    }
}
