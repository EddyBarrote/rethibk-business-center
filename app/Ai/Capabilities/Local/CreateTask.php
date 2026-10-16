<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Models\EmailMessage;
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
        return 'Cria uma tarefa e põe-na a andar. Para outro agente (pela chave): delega a quem te reporta ou escala à tua chefia. Para ti (a tua chave): numa conversa, transforma um pedido em trabalho. Para uma pessoa (person = email): da tua área ou a tua chefia ou, numa conversa, qualquer colega a quem a pessoa da conversa pode dar trabalho, em nome dela. Quando te pedem algo para um colega da plataforma, dá-lhe a tarefa em vez de escreveres uma mensagem para copiar. Recebes o resultado quando a tarefa ficar feita.';
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
            'title' => $schema->string()->description('Título curto que diga o que é e a quem ou ao quê respeita, sem códigos (por exemplo «Seguimento da proposta à Fundação AZUL»): é por ele que todos reconhecem a tarefa.')->required(),
            'description' => $schema->string()->description('O que fazer, com o contexto todo (valores, referências, datas): quem recebe não vê a tua conversa.')->required(),
            'priority' => $schema->string()->enum(array_column(TaskPriority::cases(), 'value')),
            'goal_id' => $schema->integer()->description('Objectivo da empresa que a tarefa serve, se houver.'),
            'project_id' => $schema->integer()->description('Projecto a que a tarefa pertence, se houver (tasks.list mostra os projectos).'),
            'due_at' => $schema->string()->description('Prazo, AAAA-MM-DD (ou com hora), se houver.'),
            'email_id' => $schema->integer()->description('Email de onde vem o trabalho, se houver: fica ligado à tarefa e quem a recebe pode abri-lo.'),
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
            'due_at' => 'nullable|date',
            'email_id' => 'nullable|integer',
        ])->validate();

        $parent = $context->run->task;
        $fromChat = $parent !== null && $parent->kind === TaskKind::Chat;
        $person = null;
        $target = null;

        if (filled($data['person'] ?? null)) {
            $person = User::query()->where('email', mb_strtolower(trim($data['person'])))->first();

            // In a conversation the agent may also act for its person, who could give
            // that colleague the task themselves (docs/DECISOES.md, "Passar trabalho a um colega").
            $onBehalf = $fromChat && $parent->user !== null && $parent->user->isManager();

            if ($person === null || ! ($this->chart->canAssignTo($context->agent, $person) || ($onBehalf && $person->is_active))) {
                return CapabilityResult::error($fromChat
                    ? "só podes dar tarefas a pessoas da tua área, à tua chefia ou, em nome de quem fala contigo, a quem essa pessoa pode dar trabalho; «{$data['person']}» não é."
                    : "só podes dar tarefas a pessoas da tua área ou à tua chefia; «{$data['person']}» não é.");
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

        // In a conversation the agent works for that person, so it opens work
        // only for people allowed to ask it for work (realinhamento L8).
        if ($fromChat && $parent->user !== null && ! $parent->user->can('requestWork', $context->agent)) {
            return CapabilityResult::error("{$parent->user->name} pode conversar contigo mas não pedir-te trabalho. Diz-lhe que peça acesso à chefia ou que fale com quem o tem.");
        }

        if ($parent !== null && ! $fromChat && $parent->depth() >= TaskThread::MAX_DEPTH) {
            return CapabilityResult::error('a cadeia de delegação já é longa demais; faz tu o trabalho ou pergunta a uma pessoa.');
        }

        $project = isset($data['project_id']) ? Project::query()->find($data['project_id']) : null;
        $email = isset($data['email_id']) ? EmailMessage::query()->readableBy($context->agent)->find($data['email_id']) : null;

        if (isset($data['email_id']) && $email === null) {
            return CapabilityResult::error("não encontro o email {$data['email_id']} entre os que podes ler.");
        }

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
            'due_at' => $data['due_at'] ?? null,
            'source_type' => $email?->getMorphClass(),
            'source_id' => $email?->id,
        ], $context->agent);

        if ($person !== null && $fromChat && $parent->user !== null) {
            $this->threads->note($task, "Pedido por {$parent->user->name}, na conversa com {$context->agent->name}. {$context->agent->name} acompanha: escreva aqui se tiver dúvidas e marque a tarefa como feita quando estiver.");
        }

        $owner = $target->name ?? $person->name ?? '';

        if ($parent !== null) {
            $this->threads->note($parent, $target?->id === $context->agent->id
                ? "{$context->agent->name} criou a tarefa «{$task->title}» e vai trabalhar nela."
                : "{$context->agent->name} deu a tarefa «{$task->title}» a {$owner}.", $context->run);
        }

        // The title is how people know the task; the code is only for search (docs/DECISOES.md, "Tarefas pelo título").
        return CapabilityResult::data(['title' => $task->title, 'ref' => $task->identifier(), 'task_id' => $task->id, 'link' => "/tasks/{$task->id}", 'assigned_to' => $owner]);
    }

    public function summarise(array $arguments): string
    {
        return 'Dar tarefa a '.($arguments['person'] ?? $arguments['agent'] ?? '?').': '.($arguments['title'] ?? '');
    }
}
