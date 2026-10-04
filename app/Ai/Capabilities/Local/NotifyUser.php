<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\AutonomyLevel;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

final class NotifyUser extends LocalCapability
{
    public function __construct(private readonly Notifier $notifier) {}

    public function key(): string
    {
        return 'notify.user';
    }

    public function name(): string
    {
        return 'Notificar pessoa';
    }

    public function description(): string
    {
        return 'Envia uma notificação na consola a uma pessoa da organização (por email da conta). Por omissão, a quem o agente responde.';
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
            'to' => $schema->string()->description('Email da conta da pessoa. Vazio: a chefia do agente.'),
            'title' => $schema->string()->required(),
            'body' => $schema->string()->required(),
            'link' => $schema->string()->description('Caminho na consola, ex.: /inbox/12'),
            'urgent' => $schema->boolean(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'to' => 'nullable|string|max:255',
            'title' => 'required|string|max:200',
            'body' => 'required|string|max:4000',
            'link' => ['nullable', 'string', 'max:500', 'regex:#^/[^/]#'],
            'urgent' => 'nullable|boolean',
        ])->validate();

        $user = filled($data['to'] ?? null)
            ? User::query()->where('email', mb_strtolower($data['to']))->where('is_active', true)->first()
            : $context->agent->reportsTo;

        if ($user === null) {
            return CapabilityResult::error('pessoa não encontrada nesta organização.');
        }

        $this->notifier->notify($user, $data['title'], $data['body'], $data['link'] ?? "/runs/{$context->run->id}", $context->agent->name, ($data['urgent'] ?? false) ? 'warning' : 'info');

        return CapabilityResult::data(['notified' => $user->name]);
    }

    public function summarise(array $arguments): string
    {
        return 'Notificar '.($arguments['to'] ?? 'a chefia').': '.($arguments['title'] ?? '');
    }
}
