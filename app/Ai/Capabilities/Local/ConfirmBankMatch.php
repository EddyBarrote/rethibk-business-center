<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\BankTransactionStatus;
use App\Models\AuditLog;
use App\Models\BankTransaction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Marks a bank movement as reconciled. Only a person decides this
 * (docs/DECISOES.md, realinhamento L1): the call always becomes an approval,
 * and approving it is the person's confirmation, now that the finance screen is gone.
 */
final class ConfirmBankMatch extends LocalCapability
{
    public function key(): string
    {
        return 'bank.confirm_match';
    }

    public function name(): string
    {
        return 'Reconciliar movimento';
    }

    public function description(): string
    {
        return 'Pede a uma pessoa que confirme a reconciliação de um movimento bancário (ou que o ignore). Usa depois de bank.suggest_match; a pessoa decide na aprovação.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->integer()->required(),
            'action' => $schema->string()->enum(['confirm', 'ignore'])->required(),
            'match_type' => $schema->string()->enum(SuggestBankMatch::MATCH_TYPES),
            'match_ref' => $schema->string()->description('Id no ERP (ex.: INV-0003), quando existe.'),
            'note' => $schema->string()->description('Porquê, numa linha.')->required(),
        ];
    }

    public function isMutating(): bool
    {
        return true;
    }

    public function ceilingReason(array $arguments, CapabilityContext $context): string
    {
        return 'reconciliar movimentos bancários é decidido por uma pessoa';
    }

    public function summarise(array $arguments): string
    {
        $transaction = BankTransaction::query()->find($arguments['transaction_id'] ?? 0);
        $what = ($arguments['action'] ?? 'confirm') === 'ignore' ? 'Ignorar' : 'Reconciliar';

        return $transaction === null
            ? "{$what} movimento bancário #".($arguments['transaction_id'] ?? '?')
            : "{$what} movimento de {$transaction->booked_at->format('d/m/Y')}, {$transaction->amount} MT: {$transaction->description}"
                .(filled($arguments['match_ref'] ?? null) ? " → {$arguments['match_ref']}" : '').'. '.($arguments['note'] ?? '');
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'transaction_id' => 'required|integer',
            'action' => 'required|in:confirm,ignore',
            'match_type' => 'nullable|in:'.implode(',', SuggestBankMatch::MATCH_TYPES),
            'match_ref' => 'nullable|string|max:255',
            'note' => 'required|string|max:1000',
        ])->validate();

        $transaction = BankTransaction::query()->find($data['transaction_id']);

        if ($transaction === null) {
            return CapabilityResult::error('movimento não encontrado.');
        }

        $person = $context->approval?->decided_by_user_id;

        $transaction->forceFill($data['action'] === 'confirm' ? [
            'status' => BankTransactionStatus::Reconciled,
            'match_type' => $data['match_type'] ?? $transaction->match_type ?? 'other',
            'match_ref' => $data['match_ref'] ?? $transaction->match_ref,
            'match_note' => $data['note'],
            'reconciled_by_user_id' => $person,
            'reconciled_at' => now(),
        ] : [
            'status' => BankTransactionStatus::Ignored,
            'match_note' => $data['note'],
            'reconciled_by_user_id' => $person,
            'reconciled_at' => now(),
        ])->save();

        AuditLog::record($context->agent, 'bank.transaction_'.$data['action'], ['transaction_id' => $transaction->id, 'approval_id' => $context->approval?->id], subject: $transaction);

        return CapabilityResult::data(['transaction_id' => $transaction->id, 'status' => $transaction->status->value]);
    }
}
