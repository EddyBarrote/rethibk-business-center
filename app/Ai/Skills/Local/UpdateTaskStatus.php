<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\AutonomyLevel;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class UpdateTaskStatus extends LocalSkill
{
    private const ALLOWED = [TaskStatus::InProgress, TaskStatus::InReview, TaskStatus::Blocked, TaskStatus::Done];

    public function __construct(private readonly TaskThread $threads) {}

    public function key(): string
    {
        return 'tasks.update_status';
    }

    public function name(): string
    {
        return 'Mudar estado da tarefa';
    }

    public function description(): string
    {
        return 'Marca a tua tarefa como em curso, em revisão (para uma pessoa ver), bloqueada ou feita, com uma nota curta.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Observe;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(array_map(fn (TaskStatus $s) => $s->value, self::ALLOWED))->required(),
            'note' => $schema->string()->description('Porquê, ou o resumo do resultado.'),
            'task_id' => $schema->integer()->description('Vazio: a tarefa desta conversa.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'status' => ['required', Rule::in(array_map(fn (TaskStatus $s) => $s->value, self::ALLOWED))],
            'note' => 'nullable|string|max:2000',
            'task_id' => 'nullable|integer',
        ])->validate();

        $task = isset($data['task_id']) ? Task::query()->find($data['task_id']) : $context->run->task;

        if ($task === null || $task->assignee_agent_id !== $context->agent->id) {
            return SkillResult::error('só podes mudar o estado de tarefas tuas.');
        }

        $this->threads->setStatus($task, TaskStatus::from($data['status']), $context->agent, $data['note'] ?? null);

        return SkillResult::text("{$task->identifier()} está agora «{$task->status->label()}».");
    }

    public function summarise(array $arguments): string
    {
        return 'Mudar estado para '.($arguments['status'] ?? '?');
    }
}
