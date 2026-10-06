<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\EmailCategory;
use App\Models\EmailMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;

final class SearchEmails extends LocalCapability
{
    public function key(): string
    {
        return 'email.search';
    }

    public function name(): string
    {
        return 'Pesquisar emails';
    }

    public function description(): string
    {
        return 'Procura emails por texto, remetente, categoria da triagem ou datas.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Texto no assunto ou no corpo.'),
            'from' => $schema->string()->description('Endereço ou domínio do remetente.'),
            'category' => $schema->string()->enum(array_column(EmailCategory::cases(), 'value')),
            'since' => $schema->string()->description('Data inicial, AAAA-MM-DD.'),
            'limit' => $schema->integer()->min(1)->max(50),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $messages = EmailMessage::query()->readableBy($context->agent)
            ->when($arguments['query'] ?? null, fn (Builder $q, string $text) => $q->where(fn (Builder $w) => $w->where('subject', 'like', "%{$text}%")->orWhere('text_body', 'like', "%{$text}%")))
            ->when($arguments['from'] ?? null, fn (Builder $q, string $from) => $q->where('from_address', 'like', "%{$from}%"))
            ->when($arguments['category'] ?? null, fn (Builder $q, string $category) => $q->where('classification', $category))
            ->when($arguments['since'] ?? null, fn (Builder $q, string $since) => $q->where('received_at', '>=', $since))
            ->latest('received_at')
            ->limit(min((int) ($arguments['limit'] ?? 20), 50))
            ->get(['id', 'direction', 'from_address', 'subject', 'classification', 'priority', 'status', 'received_at', 'erp_lead_id', 'deadline_at']);

        return CapabilityResult::data(['emails' => $messages->map(fn (EmailMessage $m) => [
            'id' => $m->id,
            'direction' => $m->direction,
            'from' => $m->from_address,
            'subject' => $m->subject,
            // The label, not the key: agents quote this to people ("Factura de fornecedor", not "supplier_invoice").
            'category' => $m->classification?->label(),
            'priority' => $m->priority,
            'received_at' => $m->received_at?->toIso8601String(),
            'deadline_at' => $m->deadline_at?->toIso8601String(),
            'erp_lead_id' => $m->erp_lead_id,
        ])->all()]);
    }
}
