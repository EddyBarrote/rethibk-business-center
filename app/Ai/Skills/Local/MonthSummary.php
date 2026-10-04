<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\BankTransactionStatus;
use App\Enums\EmailCategory;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Models\BankTransaction;
use App\Models\EmailMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * The figures for the month-close pack (E05): bank movement and
 * reconciliation, receivables, project margins and supplier invoices
 * received. The agent writes the pack with reports.draft.
 */
final class MonthSummary extends LocalSkill
{
    public function __construct(private readonly ErpGateway $erp) {}

    public function key(): string
    {
        return 'finance.month_summary';
    }

    public function name(): string
    {
        return 'Números do fecho do mês';
    }

    public function description(): string
    {
        return 'Junta os números de um mês para o pacote de fecho: entradas e saídas no banco, o que falta reconciliar, contas a receber, margens dos projectos e facturas de fornecedor recebidas.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['period' => $schema->string()->description('AAAA-MM; por omissão o mês anterior.')];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $data = Validator::make($arguments, ['period' => 'nullable|date_format:Y-m'])->validate();
        $start = isset($data['period']) ? Carbon::createFromFormat('!Y-m', $data['period']) : today()->subMonthNoOverflow()->startOfMonth();
        $start ??= today()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $bank = BankTransaction::query()->whereBetween('booked_at', [$start, $end]);

        return SkillResult::data([
            'period' => $start->format('Y-m'),
            'bank' => [
                'credits' => round((float) (clone $bank)->where('amount', '>', 0)->sum('amount'), 2),
                'debits' => round((float) (clone $bank)->where('amount', '<', 0)->sum('amount'), 2),
                'lines' => (clone $bank)->count(),
                'reconciled' => (clone $bank)->where('status', BankTransactionStatus::Reconciled)->count(),
                'open' => (clone $bank)->whereIn('status', [BankTransactionStatus::Unmatched, BankTransactionStatus::Suggested])->count(),
            ],
            'supplier_invoices_received' => EmailMessage::query()
                ->where('classification', EmailCategory::SupplierInvoice->value)
                ->whereBetween('received_at', [$start, $end->copy()->endOfDay()])
                ->get(['id', 'subject', 'from_address', 'extracted'])
                ->toArray(),
            'receivables' => $this->erpData('invoices.list_receivables', [], $context),
            'projects' => ($projects = $this->erpData('projects.list', ['status' => 'in_progress'], $context)) !== null
                ? ProjectMargins::analyse((array) ($projects['projects'] ?? []))
                : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|null
     */
    private function erpData(string $tool, array $arguments, SkillContext $context): ?array
    {
        try {
            $result = $this->erp->call($tool, $arguments, $context->agent, ['agent_run_id' => $context->run->id]);
        } catch (ErpException) {
            return null;
        }

        return $result->ok ? $result->data : null;
    }
}
