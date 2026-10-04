<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\BankTransactionStatus;
use App\Models\BankTransaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The agent proposes; a person confirms the reconciliation on /finance.
 */
final class SuggestBankMatch extends LocalCapability
{
    public const MATCH_TYPES = ['invoice', 'expense', 'transfer', 'fee', 'salary', 'tax', 'other'];

    public function key(): string
    {
        return 'bank.suggest_match';
    }

    public function name(): string
    {
        return 'Propor reconciliação';
    }

    public function description(): string
    {
        return 'Propõe a correspondência de um movimento bancário (factura, despesa, transferência, comissão, salários, impostos). Fica como sugestão até uma pessoa confirmar.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required(),
            'match_type' => $schema->string()->enum(self::MATCH_TYPES)->required(),
            'match_ref' => $schema->string()->description('Id no ERP (ex.: INV-0003, EXP-0001), quando existe.'),
            'note' => $schema->string()->description('Porquê, numa linha.')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'transaction_id' => 'required|integer',
            'match_type' => 'required|in:'.implode(',', self::MATCH_TYPES),
            'match_ref' => 'nullable|string|max:255',
            'note' => 'required|string|max:1000',
        ])->validate();

        $transaction = BankTransaction::query()->find($data['transaction_id']);

        if ($transaction === null) {
            return CapabilityResult::error('movimento não encontrado.');
        }

        if ($transaction->status === BankTransactionStatus::Reconciled) {
            return CapabilityResult::error('o movimento já foi reconciliado por uma pessoa.');
        }

        $transaction->forceFill([
            'status' => BankTransactionStatus::Suggested,
            'match_type' => $data['match_type'],
            'match_ref' => $data['match_ref'] ?? null,
            'match_note' => $data['note'],
            'suggested_by_run_id' => $context->run->id,
        ])->save();

        return CapabilityResult::data(['transaction_id' => $transaction->id, 'status' => 'suggested']);
    }
}
