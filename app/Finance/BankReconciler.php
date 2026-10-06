<?php

namespace App\Finance;

use App\Enums\BankTransactionStatus;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Proposes matches for bank lines: credits against open receivables in the
 * ERP (by invoice number in the description, then by exact amount), debits
 * against ERP expenses by amount. A person confirms every match.
 */
final class BankReconciler
{
    public function __construct(private readonly ErpGateway $erp) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function openWithCandidates(?Model $actor = null, int $limit = 50): array
    {
        $transactions = BankTransaction::query()
            ->whereIn('status', [BankTransactionStatus::Unmatched, BankTransactionStatus::Suggested])
            ->orderBy('booked_at')
            ->limit($limit)
            ->get();

        if ($transactions->isEmpty()) {
            return [];
        }

        $receivables = $this->receivables($actor);

        return $transactions->map(fn (BankTransaction $t) => [
            'id' => $t->id,
            'date' => $t->booked_at->toDateString(),
            'description' => $t->description,
            'reference' => $t->reference,
            'amount' => $t->amount,
            'status' => $t->status->value,
            'suggested' => $t->match_ref ? ['type' => $t->match_type, 'ref' => $t->match_ref, 'note' => $t->match_note] : null,
            'candidates' => $t->amount > 0 ? $this->invoiceCandidates($t, $receivables) : [],
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $receivables
     * @return list<array{invoice_id: string, number: string|null, account_id: string, outstanding: float, why: string}>
     */
    public function invoiceCandidates(BankTransaction $transaction, array $receivables): array
    {
        $candidates = [];
        $text = Str::upper($transaction->description.' '.$transaction->reference);

        foreach ($receivables as $invoice) {
            $number = (string) ($invoice['number'] ?? '');
            $outstanding = (float) ($invoice['outstanding'] ?? 0);
            $byNumber = $number !== '' && (str_contains($text, Str::upper($number)) || str_contains($text, (string) preg_replace('/\D/', '', $number)));
            $byAmount = abs($outstanding - $transaction->amount) < 0.01;

            if ($byNumber || $byAmount) {
                $candidates[] = [
                    'invoice_id' => (string) $invoice['id'],
                    'number' => $number ?: null,
                    'account_id' => (string) $invoice['account_id'],
                    'outstanding' => $outstanding,
                    'why' => $byNumber && $byAmount ? 'número e montante' : ($byNumber ? 'número da factura na descrição' : 'montante igual ao em dívida'),
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function receivables(?Model $actor): array
    {
        try {
            $result = $this->erp->call('invoices.list_receivables', [], $actor);
        } catch (ErpException) {
            return [];
        }

        /** @var list<array<string, mixed>> */
        return $result->ok ? array_values((array) ($result->data['receivables'] ?? [])) : [];
    }
}
