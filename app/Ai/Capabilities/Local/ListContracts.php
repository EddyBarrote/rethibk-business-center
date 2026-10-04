<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\ContractStatus;
use App\Enums\PartyType;
use App\Models\Contract;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ListContracts extends LocalCapability
{
    public function key(): string
    {
        return 'contracts.list';
    }

    public function name(): string
    {
        return 'Contratos';
    }

    public function description(): string
    {
        return 'Lista contratos de clientes ou fornecedores, opcionalmente só os que terminam dentro de N dias ou de uma entidade (id no ERP).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'party_type' => $schema->string()->enum(array_column(PartyType::cases(), 'value')),
            'party_ref' => $schema->string(),
            'ending_within_days' => $schema->integer()->min(1)->max(730),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $contracts = Contract::query()
            ->with('owner:id,name,email')
            ->whereIn('status', [ContractStatus::Active, ContractStatus::Renewing])
            ->when($arguments['party_type'] ?? null, fn ($q, $type) => $q->where('party_type', $type))
            ->when($arguments['party_ref'] ?? null, fn ($q, $ref) => $q->where('party_ref', $ref))
            ->when($arguments['ending_within_days'] ?? null, fn ($q, $days) => $q->whereNotNull('ends_at')->where('ends_at', '<=', today()->addDays((int) $days)))
            ->orderBy('ends_at')
            ->limit(50)
            ->get();

        return CapabilityResult::data(['contracts' => $contracts->map(fn (Contract $c) => [
            'id' => $c->id,
            'title' => $c->title,
            'party_type' => $c->party_type->value,
            'party' => $c->party_name,
            'party_ref' => $c->party_ref,
            'value' => $c->value,
            'ends_at' => $c->ends_at?->toDateString(),
            'days_left' => $c->ends_at ? (int) today()->diffInDays($c->ends_at, false) : null,
            'notice_days' => $c->notice_days,
            'auto_renews' => $c->auto_renews,
            'sla_response_hours' => $c->sla_response_hours,
            'status' => $c->status->value,
            'owner' => $c->owner?->only(['name', 'email']),
            'link' => "/contracts/{$c->id}",
        ])->all()]);
    }
}
