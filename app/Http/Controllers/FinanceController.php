<?php

namespace App\Http\Controllers;

use App\Ai\Capabilities\Local\SuggestBankMatch;
use App\Console\Concerns\DispatchesRoles;
use App\Enums\BankTransactionStatus;
use App\Enums\TriggerType;
use App\Finance\BankStatementImporter;
use App\Models\AuditLog;
use App\Models\BankStatement;
use App\Models\BankTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Bank reconciliation (E05): statements uploaded here or received by email,
 * the agent's proposed matches, and a person's confirmation.
 */
class FinanceController extends Controller
{
    use DispatchesRoles;

    public function index(Request $request): Response
    {
        $this->authorizeFinance($request);
        $status = $request->query('status', 'open');

        return Inertia::render('Finance/Index', [
            'statements' => BankStatement::query()->latest('id')->limit(10)->get()->map(fn (BankStatement $s) => [
                'id' => $s->id, 'account_name' => $s->account_name, 'bank' => $s->bank, 'source' => $s->source,
                'period_start' => $s->period_start?->toDateString(), 'period_end' => $s->period_end?->toDateString(),
                'closing_balance' => $s->closing_balance, 'transaction_count' => $s->transaction_count, 'original_name' => $s->original_name,
                'created_at' => $s->created_at->toIso8601String(),
            ]),
            'transactions' => BankTransaction::query()
                ->with('statement:id,account_name')
                ->when($status === 'open', fn ($q) => $q->whereIn('status', [BankTransactionStatus::Unmatched, BankTransactionStatus::Suggested]))
                ->when($status !== 'open' && $status !== 'all', fn ($q) => $q->where('status', $status))
                ->orderByRaw("case when status = 'suggested' then 0 else 1 end")
                ->orderBy('booked_at')
                ->paginate(50)
                ->withQueryString()
                ->through(fn (BankTransaction $t) => [
                    'id' => $t->id, 'date' => $t->booked_at->toDateString(), 'description' => $t->description, 'reference' => $t->reference,
                    'amount' => $t->amount, 'status' => $t->status->value, 'status_label' => $t->status->label(),
                    'match_type' => $t->match_type, 'match_ref' => $t->match_ref, 'match_note' => $t->match_note,
                    'account' => $t->statement?->account_name, 'run_id' => $t->suggested_by_run_id,
                ]),
            'totals' => [
                'unmatched' => BankTransaction::query()->where('status', BankTransactionStatus::Unmatched)->count(),
                'suggested' => BankTransaction::query()->where('status', BankTransactionStatus::Suggested)->count(),
                'reconciled' => BankTransaction::query()->where('status', BankTransactionStatus::Reconciled)->count(),
            ],
            'filter' => $status,
            'matchTypes' => SuggestBankMatch::MATCH_TYPES,
        ]);
    }

    public function upload(Request $request, BankStatementImporter $importer): RedirectResponse
    {
        $user = $this->authorizeFinance($request);
        $data = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],
            'bank' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt'],
        ]);

        $file = $request->file('file');
        $stored = $file->store('tenants/'.Tenant::current()?->id.'/bank', (string) config('mail_ingest.disk'));

        try {
            $result = $importer->importCsv((string) $file->get(), [
                'account_name' => $data['account_name'],
                'bank' => $data['bank'] ?? null,
                'source' => 'upload',
                'original_name' => $file->getClientOriginalName(),
                'path' => $stored ?: null,
                'uploaded_by_user_id' => $user->id,
            ]);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        AuditLog::record($user, 'bank.statement_uploaded', ['statement_id' => $result['statement']->id, 'imported' => $result['imported'], 'skipped' => $result['skipped']], subject: $result['statement']);

        if ($result['imported'] > 0) {
            $this->dispatchRole('finance', "Foi carregado o extracto #{$result['statement']->id} ({$data['account_name']}) com {$result['imported']} movimento(s) novo(s). Propõe as reconciliações com bank.unreconciled e bank.suggest_match.", $result['statement'], TriggerType::Manual);
        }

        return back()->with('success', "{$result['imported']} movimento(s) importado(s)".($result['skipped'] ? ", {$result['skipped']} já existiam" : '').'.');
    }

    public function reconcile(Request $request, BankTransaction $transaction): RedirectResponse
    {
        $user = $this->authorizeFinance($request);
        $data = $request->validate([
            'action' => ['required', 'in:confirm,ignore,reset'],
            'match_type' => ['nullable', 'in:'.implode(',', SuggestBankMatch::MATCH_TYPES)],
            'match_ref' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $changes = match ($data['action']) {
            'confirm' => [
                'status' => BankTransactionStatus::Reconciled,
                'match_type' => $data['match_type'] ?? $transaction->match_type ?? 'other',
                'match_ref' => $data['match_ref'] ?? $transaction->match_ref,
                'match_note' => $data['note'] ?? $transaction->match_note,
                'reconciled_by_user_id' => $user->id,
                'reconciled_at' => now(),
            ],
            'ignore' => ['status' => BankTransactionStatus::Ignored, 'match_note' => $data['note'] ?? $transaction->match_note, 'reconciled_by_user_id' => $user->id, 'reconciled_at' => now()],
            default => ['status' => BankTransactionStatus::Unmatched, 'match_type' => null, 'match_ref' => null, 'match_note' => null, 'reconciled_by_user_id' => null, 'reconciled_at' => null],
        };

        $transaction->forceFill($changes)->save();
        AuditLog::record($user, 'bank.transaction_'.$data['action'], ['transaction_id' => $transaction->id, 'amount' => $transaction->amount, 'match_type' => $transaction->match_type, 'match_ref' => $transaction->match_ref], subject: $transaction);

        return back()->with('success', 'Movimento actualizado.');
    }

    public function ask(Request $request): RedirectResponse
    {
        $user = $this->authorizeFinance($request);
        $run = $this->dispatchRole('finance', 'Revê os movimentos bancários por reconciliar (bank.unreconciled) e propõe as correspondências com bank.suggest_match. Diz no fim o que não conseguiste explicar.', trigger: TriggerType::Manual);

        return $run === null
            ? back()->with('error', 'Não há um agente de finanças activo.')
            : to_route('runs.show', $run)->with('success', "Pedido enviado ao agente (pedido de {$user->name}).");
    }

    private function authorizeFinance(Request $request): User
    {
        $user = $this->user($request);
        abort_unless($user->isManager(), 403, 'Só a direcção e as chefias vêem as finanças.');

        return $user;
    }
}
