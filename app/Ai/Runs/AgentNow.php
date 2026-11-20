<?php

namespace App\Ai\Runs;

use App\Ai\Autonomy\PeopleData;
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
        // A confidential agent's work is not shown at all to whoever may not manage people (PeopleData).
        if (PeopleData::closedTo($agent, $user)) {
            return ['active' => [], 'last' => null, 'recent' => []];
        }

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

        $present = $this->presenter($active, $user, $active->merge($recent)->push($last));

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
        if (PeopleData::closedTo($agent, $user)) {
            return [];
        }

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

        $present = $this->presenter($runs->filter(fn (AgentRun $run) => in_array($run->status, [RunStatus::Queued, RunStatus::Running], true)), $user, $runs);

        return $runs->map($present)->values()->all();
    }

    /**
     * For the home screen (docs/DECISOES.md, "Início: os agentes a trabalhar"):
     * what these agents are doing now and what they finished in the last day.
     * Conversations are left out of the finished work (their answer is in the
     * conversation); someone else's conversation is left out altogether.
     *
     * @param  Collection<int, Agent>  $agents
     * @return array{active: list<array<string, mixed>>, done: list<array<string, mixed>>}
     */
    public function overview($agents, User $user): array
    {
        $ids = $agents->pluck('id')->diff(PeopleData::hiddenAgentIds($user))->values();
        $mine = fn (AgentRun $run) => $run->task === null || $run->task->kind !== TaskKind::Chat || $run->task->user_id === $user->id;

        $active = AgentRun::query()->whereIn('agent_id', $ids)->working()
            ->with(['agent:id,name', 'requestedBy:id,name', 'task'])->oldest('id')->get()->filter($mine)->values();

        $done = AgentRun::query()->whereIn('agent_id', $ids)
            ->whereIn('status', [RunStatus::Completed, RunStatus::Failed])
            ->where('finished_at', '>=', now()->subDay())
            ->with(['agent:id,name', 'requestedBy:id,name', 'task'])
            ->latest('finished_at')
            ->limit(20)
            ->get()
            ->filter(fn (AgentRun $run) => $run->task === null || $run->task->kind !== TaskKind::Chat)
            ->take(8)
            ->values();

        $present = $this->presenter($active, $user, $active->merge($done));

        return [
            'active' => $active->map($present)->values()->all(),
            'done' => $done->map($present)->values()->all(),
        ];
    }

    /**
     * How a run is shown, with the step a live one is on. A run this person
     * may not open (AgentRunPolicy) shows that the agent worked, not on what:
     * no request, answer or error (docs/DECISOES.md, "Director de Recursos Humanos").
     *
     * @param  Collection<int, AgentRun>  $live
     * @param  iterable<AgentRun|null>  $shown  every run the closure will present
     * @return \Closure(AgentRun): array<string, mixed>
     */
    private function presenter($live, User $user, iterable $shown): \Closure
    {
        $ids = collect($shown)->filter()->map(fn (AgentRun $run) => $run->id)->unique()->values();
        $readable = AgentRun::query()->visibleTo($user)->whereIn('id', $ids)->pluck('id')->flip();

        $steps = AgentRunStep::query()
            ->whereIn('agent_run_id', $live->pluck('id'))
            ->whereIn('type', [StepType::ToolCall, StepType::Reasoning, StepType::Message, StepType::Approval])
            ->orderByDesc('seq')
            ->get()
            ->unique('agent_run_id')
            ->keyBy('agent_run_id');

        return fn (AgentRun $run) => [
            ...Present::run($run),
            ...($readable->has($run->id) ? [] : ['title' => null, 'input' => '', 'output' => null, 'error' => null]),
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
