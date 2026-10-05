<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * A reminder for a person or for the agent itself; `followups:notify`
 * delivers it when due.
 */
final class ScheduleFollowUp extends LocalCapability
{
    public function key(): string
    {
        return 'followups.schedule';
    }

    public function name(): string
    {
        return 'Agendar seguimento';
    }

    public function description(): string
    {
        return 'Agenda um lembrete de seguimento (por exemplo, voltar a um cliente que não respondeu) para uma data.';
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
            'title' => $schema->string()->required(),
            'due' => $schema->string()->description('AAAA-MM-DD ou AAAA-MM-DD HH:MM (hora de Maputo).')->required(),
            'note' => $schema->string(),
            'for' => $schema->string()->description('Email da pessoa. Vazio: a chefia do agente.'),
            'email_id' => $schema->integer()->description('Email a que o seguimento se refere.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'title' => 'required|string|max:255',
            'due' => 'required|date',
            'note' => 'nullable|string|max:4000',
            'for' => 'nullable|string|max:255',
            'email_id' => 'nullable|integer',
        ])->validate();

        $user = filled($data['for'] ?? null) ? User::query()->where('email', mb_strtolower($data['for']))->first() : $context->agent->reportsTo;
        $email = isset($data['email_id']) ? EmailMessage::query()->readableBy($context->agent)->find($data['email_id']) : null;

        $followUp = FollowUp::query()->create([
            'agent_id' => $context->agent->id,
            'user_id' => $user?->id,
            'subject_type' => $email?->getMorphClass(),
            'subject_id' => $email?->id,
            'title' => $data['title'],
            'note' => $data['note'] ?? null,
            'due_at' => Carbon::parse($data['due'], (string) config('agents.schedule_timezone'))->utc(),
        ]);

        return CapabilityResult::data(['follow_up_id' => $followUp->id, 'due_at' => $followUp->due_at->toIso8601String(), 'for' => $user?->name]);
    }

    public function summarise(array $arguments): string
    {
        return 'Seguimento: '.($arguments['title'] ?? '').' ('.($arguments['due'] ?? '').')';
    }
}
