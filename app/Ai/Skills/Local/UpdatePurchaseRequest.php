<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\PurchaseRequestStatus;
use App\Models\PurchaseRequest;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Moves a requisition along (RFQ, quotes, comparison, draft PO, delivery)
 * and records the ERP references. Platform data only.
 */
final class UpdatePurchaseRequest extends LocalSkill
{
    public function key(): string
    {
        return 'procurement.update_request';
    }

    public function name(): string
    {
        return 'Actualizar requisição';
    }

    public function description(): string
    {
        return 'Muda o estado de uma requisição e guarda as referências do ERP (pedido de cotação, nota de encomenda) e notas.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'request_id' => $schema->integer()->required(),
            'status' => $schema->string()->enum(array_column(PurchaseRequestStatus::cases(), 'value')),
            'erp_rfq_id' => $schema->string(),
            'erp_po_id' => $schema->string(),
            'note' => $schema->string()->description('Acrescentada às notas, com data.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, [
            'request_id' => 'required|integer',
            'status' => ['nullable', Rule::enum(PurchaseRequestStatus::class)],
            'erp_rfq_id' => 'nullable|string|max:255',
            'erp_po_id' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
        ])->validate();

        $request = PurchaseRequest::query()->find($data['request_id']);

        if ($request === null) {
            return SkillResult::error('requisição não encontrada.');
        }

        $request->fill(array_filter([
            'status' => $data['status'] ?? null,
            'erp_rfq_id' => $data['erp_rfq_id'] ?? null,
            'erp_po_id' => $data['erp_po_id'] ?? null,
        ], fn ($value) => $value !== null));

        if (filled($data['note'] ?? null)) {
            $request->notes = trim(($request->notes ? $request->notes."\n" : '').now()->format('d/m/Y H:i').' '.$context->agent->name.': '.$data['note']);
        }

        $request->save();

        return SkillResult::data(['request_id' => $request->id, 'status' => $request->status->value]);
    }
}
