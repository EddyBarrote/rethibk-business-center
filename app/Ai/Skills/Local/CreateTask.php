<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\AutonomyLevel;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Models\Goal;
use App\Tasks\OrgChart;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Delegate work to another agent, following the org chart. When it is done,
 * this agent is told in its own thread.
 */
final class CreateTask extends LocalSkill
{
    public function __construct(private readonly TaskThread $threads, private readonly OrgChart $chart) {}

    public function key(): string
    {
        return 'tasks.create';
    }

    public function name(): string
    {
        return 'Delegar tarefa';
    }

    public function description(): string
    {
        return 'Cria uma tarefa para outro agente (pela chave) e põe-no a trabalhar. Delega a quem te reporta ou escala à tua chefia. Recebes o resultado quando ele a marcar como feita.';
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Suggest;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent' => $schema->string()->description('Chave (ou nome) do agente que fica com a tarefa.')->required(),
            'title' => $schema->string()->required(),
            'description' => $schema->string()->description('O que fazer, com o contexto todo: o outro agente não vê a tua conversa.')->required(),
            'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value')),
            'goal_id' => $schema->integer()->description('Objectivo da empresa que a tarefa serve, se houver.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'agent' => 'required|string|max:120',
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:8000',
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'goal_id' => 'nullable|integer',
        ])->validate();

        $target = $this->chart->find($data['agent']);

        if ($target === null || ! $target->isActive()) {
            return SkillResult::error("não há nenhum agente activo «{$data['agent']}».");
        }

        if (! $this->chart->canDelegate($context->agent, $target)) {
            return SkillResult::error("{$target->name} não está abaixo de ti nem é a tua chefia no organigrama.");
        }

        $parent = $context->run->task;

        if ($parent !== null && $parent->depth() >= TaskThread::MAX_DEPTH) {
            return SkillResult::error('a cadeia de delegação já é longa demais; faz tu o trabalho ou pergunta a uma pessoa.');
        }

        $task = $this->threads->open([
            'kind' => TaskKind::Task,
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'assignee_agent_id' => $target->id,
            'user_id' => $parent?->user_id,
            'goal_id' => isset($data['goal_id']) && Goal::query()->whereKey($data['goal_id'])->exists() ? $data['goal_id'] : $parent?->goal_id,
            'parent_id' => $parent?->id,
        ], $context->agent);

        if ($parent !== null) {
            $this->threads->note($parent, "{$context->agent->name} delegou {$task->identifier()} «{$task->title}» a {$target->name}.", $context->run);
        }

        return SkillResult::data(['task' => $task->identifier(), 'task_id' => $task->id, 'assigned_to' => $target->name]);
    }

    public function summarise(array $arguments): string
    {
        return 'Delegar a '.($arguments['agent'] ?? '?').': '.($arguments['title'] ?? '');
    }
}
