<?php

namespace App\Insights;

use App\Enums\AgentStatus;
use App\Enums\ApprovalStatus;
use App\Enums\BankTransactionStatus;
use App\Enums\ContractStatus;
use App\Enums\EmailCategory;
use App\Enums\PurchaseRequestStatus;
use App\Enums\RunStatus;
use App\Enums\TenderStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\BankTransaction;
use App\Models\Contract;
use App\Models\EmailMessage;
use App\Models\FollowUp;
use App\Models\PurchaseRequest;
use App\Models\Tender;
use App\Support\TenantSettings;

/**
 * Blockers and inconsistencies across areas (E04). Rule based, so the Chief
 * of Staff and the direction dashboard see the same list, and so it costs no
 * tokens to compute.
 */
final class IssueDetector
{
    /**
     * @return list<array{area: string, severity: string, issue: string, link: string|null}>
     */
    public function detect(): array
    {
        $issues = [];
        $add = function (string $area, string $severity, string $issue, ?string $link = null) use (&$issues): void {
            $issues[] = ['area' => $area, 'severity' => $severity, 'issue' => $issue, 'link' => $link];
        };

        foreach (Approval::query()->where('status', ApprovalStatus::Pending)->where('created_at', '<', now()->subDay())->with('agent')->limit(20)->get() as $approval) {
            $add('Aprovações', 'alta', "Aprovação pendente há mais de 24 h: {$approval->action_summary} ({$approval->agent?->name})", '/approvals');
        }

        foreach (Agent::query()->where('status', AgentStatus::Suspended)->get() as $agent) {
            $add('Agentes', 'alta', "{$agent->name} está suspenso".($agent->suspended_reason ? ": {$agent->suspended_reason}" : '.'), "/agents/{$agent->id}");
        }

        $failed = AgentRun::query()->where('status', RunStatus::Failed)->where('created_at', '>=', now()->subDay())->count();
        if ($failed > 0) {
            $add('Agentes', 'média', ($failed === 1 ? '1 execução falhou' : "{$failed} execuções falharam").' nas últimas 24 h.', '/runs?status=failed');
        }

        foreach (EmailMessage::query()->where('direction', 'inbound')->where('classification', EmailCategory::Lead->value)->whereNull('erp_lead_id')->where('status', 'processed')->limit(20)->get() as $email) {
            $add('Comercial', 'média', "Email classificado como lead sem lead no ERP: {$email->subject}", "/inbox/{$email->id}");
        }

        $warning = TenantSettings::int('deadline_warning_hours');
        foreach (Tender::query()->whereIn('status', [TenderStatus::New, TenderStatus::Reviewing])->whereBetween('deadline_at', [now(), now()->addHours(max($warning, 72))])->get() as $tender) {
            $add('Concursos', 'alta', "Concurso a fechar em {$tender->deadline_at?->diffForHumans()} ainda sem decisão: {$tender->title}");
        }

        foreach (FollowUp::query()->whereNull('done_at')->where('due_at', '<', now()->subDay())->limit(20)->get() as $followUp) {
            $add('Seguimentos', 'média', "Seguimento em atraso: {$followUp->title}", '/inbox');
        }

        foreach (Contract::query()->where('status', ContractStatus::Active)->whereNotNull('ends_at')->where('ends_at', '<=', today()->addDays(120))->get() as $contract) {
            if ($contract->ends_at !== null && $contract->ends_at->lte(today()->addDays($contract->notice_days))) {
                $add('Contratos', $contract->ends_at->isPast() ? 'alta' : 'média', "Contrato «{$contract->title}» com {$contract->party_name} termina a {$contract->ends_at->format('d/m/Y')}");
            }
        }

        foreach (PurchaseRequest::query()->whereNotIn('status', [PurchaseRequestStatus::Ordered, PurchaseRequestStatus::Received, PurchaseRequestStatus::Cancelled])->whereNotNull('needed_by')->where('needed_by', '<', today()->addDays(3))->get() as $request) {
            $add('Compras', 'alta', "Requisição «{$request->title}» precisa de estar entregue até {$request->needed_by?->format('d/m/Y')} e ainda está em «{$request->status->label()}».");
        }

        foreach (PurchaseRequest::query()->where('status', PurchaseRequestStatus::Ordered)->whereNull('erp_po_id')->get() as $request) {
            $add('Compras', 'média', "Requisição «{$request->title}» marcada como encomendada sem nota de encomenda no ERP.");
        }

        $stale = BankTransaction::query()->where('status', BankTransactionStatus::Unmatched)->where('booked_at', '<', today()->subDays(TenantSettings::int('unreconciled_days')))->count();
        if ($stale > 0) {
            $add('Finanças', 'média', ($stale === 1 ? '1 movimento bancário' : "{$stale} movimentos bancários").' por reconciliar há mais de '.TenantSettings::int('unreconciled_days').' dias.');
        }

        foreach ((new SlaMonitor)->breaches() as $breach) {
            $add('Clientes', 'alta', "Pedido de cliente sem resposta há {$breach['hours_waiting']} h (SLA {$breach['sla_hours']} h): {$breach['subject']}", "/inbox/{$breach['email_id']}");
        }

        return $issues;
    }
}
