<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Models\Task;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The agent's open work: what it owns and what it delegated.
 */
final class ListTasks extends LocalSkill
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

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $row = fn (Task $task) => [
            'id' => $task->id,
            'ref' => $task->identifier(),
            'title' => $task->title,
            'status' => $task->status->label(),
            'agent' => $task->assigneeAgent?->name,
        ];

        return SkillResult::data([
            'mine' => Task::query()->open()->where('assignee_agent_id', $context->agent->id)->with('assigneeAgent:id,name')->latest('last_activity_at')->limit(20)->get()->map($row)->all(),
            'delegated' => Task::query()->open()->where('created_by_agent_id', $context->agent->id)->with('assigneeAgent:id,name')->latest('last_activity_at')->limit(20)->get()->map($row)->all(),
        ]);
    }
}
