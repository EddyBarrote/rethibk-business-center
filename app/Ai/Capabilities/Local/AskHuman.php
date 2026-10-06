<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
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
final class AskHuman extends LocalCapability
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
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'question' => 'required|string|max:4000',
            'to' => 'nullable|string|max:255',
        ])->validate();

        $task = $context->run->task;
        $person = filled($data['to'] ?? null)
            ? User::query()->where('email', mb_strtolower($data['to']))->where('is_active', true)->first()
            : ($task->user ?? $task->createdByUser ?? $context->agent->reportsTo);

        if ($person === null) {
            return CapabilityResult::error('pessoa não encontrada nesta organização.');
        }

        if ($task !== null && $task->assignee_agent_id === $context->agent->id && in_array($task->user_id ?? $task->created_by_user_id, [$person->id, null], true)) {
            $task->forceFill(['user_id' => $person->id])->save();
            $this->threads->post($task, $context->agent, $data['question'], wake: false, notify: false);
            $this->threads->setStatus($task, TaskStatus::WaitingHuman, $context->agent, $data['question']);

            return CapabilityResult::text("Pergunta feita a {$person->name} em {$task->identifier()}. A resposta chega nesta conversa; não repitas a pergunta.");
        }

        // Outside its own task, the question goes into the agent's one conversation with that person.
        $chat = $this->threads->conversation($person, $context->agent);
        $this->threads->post($chat, $context->agent, $data['question'], wake: false, notify: false);
        $this->threads->setStatus($chat, TaskStatus::WaitingHuman, $context->agent, $data['question']);

        return CapabilityResult::text("Perguntei a {$person->name} na vossa conversa ({$chat->identifier()}). Quando responder, recebes a resposta lá.");
    }

    public function summarise(array $arguments): string
    {
        return 'Perguntar a '.($arguments['to'] ?? 'quem pediu').': '.mb_strimwidth((string) ($arguments['question'] ?? ''), 0, 120, '…');
    }
}
