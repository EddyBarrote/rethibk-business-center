<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Models\Goal;
use App\Models\Project;
use App\Models\User;
use App\Tasks\OrgChart;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Delegate work to another agent, following the org chart. When it is done,
 * this agent is told in its own thread.
 */
final class CreateTask extends LocalCapability
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
        return 'Cria uma tarefa e põe-na a andar. Para outro agente (pela chave): delega a quem te reporta ou escala à tua chefia. Para ti (a tua chave): numa conversa, transforma um pedido em trabalho. Para uma pessoa da tua área ou a tua chefia (person = email). Recebes o resultado quando a tarefa delegada ficar feita.';
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
            'agent' => $schema->string()->description('Chave (ou nome) do agente que fica com a tarefa; a tua chave para a criares para ti. Vazio se for para uma pessoa.'),
            'person' => $schema->string()->description('Email da pessoa que fica com a tarefa, em vez de um agente.'),
            'title' => $schema->string()->required(),
            'description' => $schema->string()->description('O que fazer, com o contexto todo: o outro agente não vê a tua conversa.')->required(),
            'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value')),
            'goal_id' => $schema->integer()->description('Objectivo da empresa que a tarefa serve, se houver.'),
            'project_id' => $schema->integer()->description('Projecto a que a tarefa pertence, se houver (tasks.list mostra os projectos).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'agent' => 'nullable|required_without:person|string|max:120',
            'person' => 'nullable|string|max:255',
            'title' => 'required|string|max:200',
            'description' => 'required|string|max:8000',
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'goal_id' => 'nullable|integer',
            'project_id' => 'nullable|integer',
        ])->validate();

        $parent = $context->run->task;
        $fromChat = $parent !== null && $parent->kind === TaskKind::Chat;
        $person = null;
        $target = null;

        if (filled($data['person'] ?? null)) {
            $person = User::query()->where('email', mb_strtolower(trim($data['person'])))->first();

            if ($person === null || ! $this->chart->canAssignTo($context->agent, $person)) {
                return CapabilityResult::error("só podes dar tarefas a pessoas da tua área ou à tua chefia; «{$data['person']}» não é.");
            }
        } else {
            $target = $this->chart->find((string) $data['agent']);

            if ($target === null || ! $target->isActive()) {
                return CapabilityResult::error("não há nenhum agente activo «{$data['agent']}».");
            }

            if ($target->id !== $context->agent->id && ! $this->chart->canDelegate($context->agent, $target)) {
                return CapabilityResult::error("{$target->name} não está abaixo de ti nem é a tua chefia no organigrama.");
            }
        }

        if ($parent !== null && ! $fromChat && $parent->depth() >= TaskThread::MAX_DEPTH) {
            return CapabilityResult::error('a cadeia de delegação já é longa demais; faz tu o trabalho ou pergunta a uma pessoa.');
        }

        $project = isset($data['project_id']) ? Project::query()->find($data['project_id']) : null;

        $task = $this->threads->open([
            'kind' => TaskKind::Task,
            'title' => $data['title'],
            'description' => $data['description'],
            'priority' => $data['priority'] ?? TaskPriority::Normal->value,
            'assignee_agent_id' => $target?->id,
            'assignee_user_id' => $person?->id,
            'user_id' => $parent?->user_id,
            'goal_id' => isset($data['goal_id']) && Goal::query()->whereKey($data['goal_id'])->exists() ? $data['goal_id'] : ($project->goal_id ?? $parent?->goal_id),
            'project_id' => $project->id ?? $parent?->project_id,
            // A conversation is not a parent: the work it asks for is a task of its own.
            'parent_id' => $fromChat ? null : $parent?->id,
        ], $context->agent);

        $owner = $target->name ?? $person->name ?? '';

        if ($parent !== null) {
            $this->threads->note($parent, $target?->id === $context->agent->id
                ? "{$context->agent->name} criou {$task->identifier()} «{$task->title}» e vai trabalhar nela."
                : "{$context->agent->name} deu {$task->identifier()} «{$task->title}» a {$owner}.", $context->run);
        }

        return CapabilityResult::data(['task' => $task->identifier(), 'task_id' => $task->id, 'link' => "/tasks/{$task->id}", 'assigned_to' => $owner]);
    }

    public function summarise(array $arguments): string
    {
        return 'Dar tarefa a '.($arguments['person'] ?? $arguments['agent'] ?? '?').': '.($arguments['title'] ?? '');
    }
}
