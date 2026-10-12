<?php

namespace App\Ai\Runs;

use App\Enums\RunStatus;
use App\Enums\StepType;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Task;
use App\Models\User;

/**
 * What an agent is doing right now: its live runs, each with its conversation
 * or task and the step it is on, by the same rule the sidebar uses to say it
 * is working (AgentRun::working). Shown at the top of the agent's page and,
 * for work elsewhere, in the person's conversation with it (docs/DECISOES.md,
 * "Agente: o que está a fazer" and "Agente ocupado noutro sítio").
 */
class AgentNow
{
    /**
     * The live runs and, when idle, the last finished one. Runs on
     * $exceptTask (the conversation already on screen) are left out.
     *
     * @return array{active: list<array<string, mixed>>, last: array<string, mixed>|null}
     */
    public function for(Agent $agent, User $user, ?Task $exceptTask = null, bool $withLast = true): array
    {
        $active = $agent->runs()
            ->working()
            ->when($exceptTask !== null, fn ($q) => $q->where(fn ($q) => $q->whereNull('task_id')->orWhere('task_id', '!=', $exceptTask->id)))
            ->with(['agent:id,name', 'requestedBy:id,name', 'task'])
            ->oldest('id')
            ->get();

        $last = $withLast && $active->isEmpty()
            ? $agent->runs()->whereNotIn('status', [RunStatus::Queued, RunStatus::Running])->with(['agent:id,name', 'requestedBy:id,name', 'task'])->latest('id')->first()
            : null;

        $steps = AgentRunStep::query()
            ->whereIn('agent_run_id', $active->pluck('id'))
            ->whereIn('type', [StepType::ToolCall, StepType::Reasoning, StepType::Message, StepType::Approval])
            ->orderByDesc('seq')
            ->get()
            ->unique('agent_run_id')
            ->keyBy('agent_run_id');

        $present = fn (AgentRun $run) => [
            ...Present::run($run),
            'started_at' => $run->started_at?->toIso8601String(),
            'task' => $this->runTask($run->task, $user),
            'step' => isset($steps[$run->id]) ? [
                'type' => $steps[$run->id]->type->value,
                'tool_name' => $steps[$run->id]->tool_name,
                'at' => $steps[$run->id]->created_at->toIso8601String(),
            ] : null,
        ];

        return [
            'active' => $active->map($present)->values()->all(),
            'last' => $last ? $present($last) : null,
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
