<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ListPurchaseRequests extends LocalSkill
{
    public function key(): string
    {
        return 'procurement.requests';
    }

    public function name(): string
    {
        return 'Requisições de compra';
    }

    public function description(): string
    {
        return 'Lista as requisições de compra (ou uma, pelo id) com artigos, prazo, orçamento, estado e referências no ERP.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->integer(),
            'status' => $schema->string()->enum(array_column(PurchaseRequestStatus::cases(), 'value')),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $query = PurchaseRequest::query()->with(['requester:id,name,email', 'department:id,name'])->latest();

        if (isset($arguments['request_id'])) {
            $query->whereKey((int) $arguments['request_id']);
        } elseif (isset($arguments['status'])) {
            $query->where('status', (string) $arguments['status']);
        } else {
            $query->whereNotIn('status', [PurchaseRequestStatus::Received, PurchaseRequestStatus::Cancelled]);
        }

        return SkillResult::data(['requests' => $query->limit(30)->get()->map(fn (PurchaseRequest $r) => [
            'id' => $r->id,
            'title' => $r->title,
            'description' => $r->description,
            'items' => $r->items,
            'needed_by' => $r->needed_by?->toDateString(),
            'budget' => $r->budget,
            'project_ref' => $r->project_ref,
            'status' => $r->status->value,
            'erp_rfq_id' => $r->erp_rfq_id,
            'erp_po_id' => $r->erp_po_id,
            'requested_by' => $r->requester?->only(['name', 'email']),
            'department' => $r->department?->name,
            'notes' => $r->notes,
        ])->all()]);
    }
}
