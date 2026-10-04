<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\AutonomyLevel;
use App\Enums\TaskKind;
use App\Enums\TaskStatus;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The agent starts the conversation: it asks a person something and waits.
 * Inside a task, the question goes in that thread; otherwise it opens a new
 * conversation with the person. Their answer wakes the agent again.
 */
final class AskHuman extends LocalSkill
{
    public function __construct(private readonly TaskThread $threads) {}

    public function key(): string
    {
        return 'tasks.ask_human';
    }

    public function name(): string
    {
        return 'Perguntar a uma pessoa';
    }

    public function description(): string
    {
        return 'Faz uma pergunta a uma pessoa e fica à espera da resposta, que te chega na conversa. Por omissão pergunta a quem pediu a tarefa ou à tua chefia.';
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
            'question' => $schema->string()->required(),
            'to' => $schema->string()->description('Email da conta da pessoa. Vazio: quem pediu a tarefa, ou a tua chefia.'),
            'title' => $schema->string()->description('Assunto, quando abre uma conversa nova.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'question' => 'required|string|max:4000',
            'to' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:200',
        ])->validate();

        $task = $context->run->task;
        $person = filled($data['to'] ?? null)
            ? User::query()->where('email', mb_strtolower($data['to']))->where('is_active', true)->first()
            : ($task->user ?? $task->createdByUser ?? $context->agent->reportsTo);

        if ($person === null) {
            return SkillResult::error('pessoa não encontrada nesta organização.');
        }

        if ($task !== null && $task->assignee_agent_id === $context->agent->id && in_array($task->user_id ?? $task->created_by_user_id, [$person->id, null], true)) {
            $task->forceFill(['user_id' => $person->id])->save();
            $this->threads->post($task, $context->agent, $data['question'], wake: false, notify: false);
            $this->threads->setStatus($task, TaskStatus::WaitingHuman, $context->agent, $data['question']);

            return SkillResult::text("Pergunta feita a {$person->name} em {$task->identifier()}. A resposta chega nesta conversa; não repitas a pergunta.");
        }

        $chat = $this->threads->open([
            'kind' => TaskKind::Chat,
            'title' => $data['title'] ?? mb_strimwidth($data['question'], 0, 120, '…'),
            'status' => TaskStatus::WaitingHuman,
            'assignee_agent_id' => $context->agent->id,
            'user_id' => $person->id,
            'parent_id' => $task?->id,
        ], $context->agent, $data['question'], start: false);

        return SkillResult::text("Abri a conversa {$chat->identifier()} com {$person->name}. Quando responder, recebes a resposta lá.");
    }

    public function summarise(array $arguments): string
    {
        return 'Perguntar a '.($arguments['to'] ?? 'quem pediu').': '.mb_strimwidth((string) ($arguments['question'] ?? ''), 0, 120, '…');
    }
}
