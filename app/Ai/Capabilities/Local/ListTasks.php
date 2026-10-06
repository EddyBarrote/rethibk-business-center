<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The agent's open work: what it owns and what it delegated.
 */
final class ListTasks extends LocalCapability
{
    public function key(): string
    {
        return 'tasks.list';
    }

    public function name(): string
    {
        return 'Ver as minhas tarefas';
    }

    public function description(): string
    {
        return 'Lista as tarefas abertas atribuídas a ti e as que delegaste a outros agentes, com o estado.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $row = fn (Task $task) => [
            'id' => $task->id,
            'ref' => $task->identifier(),
            'title' => $task->title,
            'status' => $task->status->label(),
            'agent' => $task->assigneeAgent?->name,
        ];

        return CapabilityResult::data([
            'mine' => Task::query()->open()->where('assignee_agent_id', $context->agent->id)->with('assigneeAgent:id,name')->latest('last_activity_at')->limit(20)->get()->map($row)->all(),
            'delegated' => Task::query()->open()->where('created_by_agent_id', $context->agent->id)->with('assigneeAgent:id,name')->latest('last_activity_at')->limit(20)->get()->map($row)->all(),
            'projects' => Project::query()->whereIn('status', [ProjectStatus::Planned, ProjectStatus::Active])->orderBy('name')->get(['id', 'name', 'goal_id'])->toArray(),
        ]);
    }
}
