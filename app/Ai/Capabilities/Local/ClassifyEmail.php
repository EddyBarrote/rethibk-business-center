<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Runs\AgentDirectory;
use App\Ai\Runs\AgentRunner;
use App\Enums\EmailCategory;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\Department;
use App\Models\EmailMessage;
use App\Models\User;
use App\Support\Notifier;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The triage verdict on an email (E03): category, priority, summary, the
 * fields that matter, who handles it and by when. Organising platform data
 * only, so it runs at any autonomy level (N0, "observa e organiza").
 */
final class ClassifyEmail extends LocalCapability
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly AgentDirectory $agents,
        private readonly AgentRunner $runner,
    ) {}

    public function key(): string
    {
        return 'email.classify';
    }

    public function name(): string
    {
        return 'Triar email';
    }

    public function description(): string
    {
        return 'Regista a triagem de um email: categoria, prioridade, resumo, campos extraídos, prazo e encaminhamento (departamento e/ou pessoa, que é notificada).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'email_id' => $schema->integer()->required(),
            'category' => $schema->string()->enum(array_column(EmailCategory::cases(), 'value'))->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'priority' => $schema->string()->enum(['low', 'normal', 'high', 'urgent'])->required(),
            'summary' => $schema->string()->description('Uma ou duas frases, em português.')->required(),
            'fields' => $schema->object([
                'company' => $schema->string(),
                'contact_name' => $schema->string(),
                'contact_email' => $schema->string(),
                'phone' => $schema->string(),
                'nuit' => $schema->string(),
                'reference' => $schema->string(),
                'estimated_value' => $schema->number(),
                'currency' => $schema->string(),
                'location' => $schema->string(),
                'invoice_number' => $schema->string(),
                'due_date' => $schema->string(),
            ])->description('Campos extraídos, só os que existirem.'),
            'deadline' => $schema->string()->description('Prazo de resposta ou de submissão, AAAA-MM-DD ou AAAA-MM-DD HH:MM.'),
            'department' => $schema->string()->description('Nome ou slug do departamento que deve tratar.'),
            'route_to' => $schema->string()->description('Email da pessoa que deve tratar.'),
            'flags' => $schema->array()->items($schema->string())->description('Sinais: prompt_injection, phishing, urgent_client, duplicate…'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'email_id' => 'required|integer',
            'category' => ['required', Rule::enum(EmailCategory::class)],
            'confidence' => 'required|numeric|between:0,1',
            'priority' => 'required|in:low,normal,high,urgent',
            'summary' => 'required|string|max:2000',
            'fields' => 'nullable|array',
            'deadline' => 'nullable|string|max:40',
            'department' => 'nullable|string|max:255',
            'route_to' => 'nullable|string|max:255',
            'flags' => 'nullable|array',
            'flags.*' => 'string|max:50',
        ])->validate();

        $message = EmailMessage::query()->find($data['email_id']);

        if ($message === null) {
            return CapabilityResult::error('email não encontrado.');
        }

        $department = filled($data['department'] ?? null)
            ? Department::query()->where('slug', $data['department'])->orWhere('name', $data['department'])->first()
            : null;
        $user = filled($data['route_to'] ?? null) ? User::query()->where('email', mb_strtolower($data['route_to']))->where('is_active', true)->first() : null;
        $deadline = $this->date($data['deadline'] ?? null);

        $message->forceFill([
            'classification' => $data['category'],
            'classification_confidence' => round((float) $data['confidence'], 3),
            'priority' => $data['priority'],
            'summary' => $data['summary'],
            'extracted' => array_filter(Arr::wrap($data['fields'] ?? []), fn ($v) => filled($v)),
            'flags' => array_values(array_unique([...($message->flags ?? []), ...($data['flags'] ?? [])])) ?: null,
            'deadline_at' => $deadline,
            'department_id' => $department?->id,
            'routed_to_user_id' => $user->id ?? $message->routed_to_user_id,
        ])->save();

        $notified = null;
        $recipient = $user ?? $department?->users()->where('is_active', true)->whereIn('role', ['owner', 'admin', 'manager'])->first();

        if ($recipient !== null && $data['category'] !== EmailCategory::Spam->value) {
            $label = EmailCategory::from($data['category'])->label();
            $this->notifier->notify(
                $recipient,
                "{$label}: {$message->subject}",
                $data['summary'].($deadline ? ' Prazo: '.$deadline->format('d/m/Y').'.' : ''),
                "/inbox/{$message->id}",
                $context->agent->name,
                in_array($data['priority'], ['high', 'urgent'], true) ? 'warning' : 'info',
            );
            $notified = $recipient->name;
        }

        $handler = $this->handOff($message, EmailCategory::from($data['category']), $context);

        return CapabilityResult::data([
            'email_id' => $message->id,
            'category' => $data['category'],
            'routed_to' => $notified,
            'handed_to_agent' => $handler?->name,
            'department' => $department?->name,
            'deadline' => $deadline?->toIso8601String(),
            'warnings' => array_values(array_filter([
                filled($data['route_to'] ?? null) && $user === null ? "Não existe utilizador activo {$data['route_to']}." : null,
                filled($data['department'] ?? null) && $department === null ? "Não existe o departamento {$data['department']}." : null,
            ])),
        ]);
    }

    /**
     * Supplier invoices go to the finance agent, quotes to procurement, CVs
     * to HR and client requests to the client manager, when those agents
     * exist and are active. Once per email.
     */
    private function handOff(EmailMessage $message, EmailCategory $category, CapabilityContext $context): ?Agent
    {
        $role = $category->handlerRole();
        $agent = $role !== null ? $this->agents->forRole($role) : null;

        if ($agent === null || $agent->id === $context->agent->id || $message->hasFlag('handed_off')) {
            return null;
        }

        $message->forceFill(['flags' => [...($message->flags ?? []), 'handed_off']])->save();

        $this->runner->dispatch(
            $agent,
            "A triagem classificou o email #{$message->id} como «{$category->label()}». Lê-o com email.read e trata-o dentro das tuas competências.",
            TriggerType::Agent,
            source: $message,
        );

        return $agent;
    }

    private function date(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value, (string) config('agents.schedule_timezone'))->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
