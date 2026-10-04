<?php

namespace App\Ai\Skills\Local;

use App\Ai\Budget\BudgetGuard;
use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Enums\ApprovalStatus;
use App\Enums\BankTransactionStatus;
use App\Enums\ContractStatus;
use App\Enums\KnowledgeType;
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
use App\Models\KnowledgeItem;
use App\Models\PurchaseRequest;
use App\Models\Report;
use App\Models\Tender;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A snapshot of every area of the organisation for the Chief of Staff (E04):
 * what the agents did, what waits on people, and what is coming up.
 */
final class OrganisationOverview extends LocalSkill
{
    public function __construct(private readonly BudgetGuard $budget) {}

    public function key(): string
    {
        return 'platform.overview';
    }

    public function name(): string
    {
        return 'Visão geral da organização';
    }

    public function description(): string
    {
        return 'Fotografia de todas as áreas num período: trabalho dos agentes, aprovações pendentes, caixa de entrada, concursos, compras, contratos, banco, documentos por rever e decisões recentes.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->integer()->min(1)->max(24 * 31)->description('Janela em horas; por omissão 24 (use 168 para a semana).'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $since = now()->subHours((int) ($arguments['hours'] ?? 24));
        $runs = AgentRun::query()->where('created_at', '>=', $since);

        return SkillResult::data([
            'since' => $since->toIso8601String(),
            'agents' => Agent::query()->with('department')->get()->map(fn (Agent $agent) => [
                'name' => $agent->name,
                'status' => $agent->status->value,
                'autonomy' => $agent->autonomy_level->code(),
                'runs' => (clone $runs)->where('agent_id', $agent->id)->count(),
                'failed' => (clone $runs)->where('agent_id', $agent->id)->where('status', RunStatus::Failed)->count(),
                'month_cost_usd' => round($this->budget->agentSpent($agent), 4),
            ])->all(),
            'approvals_pending' => Approval::query()->where('status', ApprovalStatus::Pending)->with('agent')->oldest()->limit(15)->get()
                ->map(fn (Approval $a) => ['id' => $a->id, 'agent' => $a->agent?->name, 'action' => $a->action_summary, 'since' => $a->created_at->toIso8601String(), 'link' => '/approvals'])->all(),
            'inbox' => [
                'received' => EmailMessage::query()->where('direction', 'inbound')->where('received_at', '>=', $since)->count(),
                'by_category' => EmailMessage::query()->where('direction', 'inbound')->where('received_at', '>=', $since)->selectRaw('classification, count(*) as total')->groupBy('classification')->pluck('total', 'classification'),
                'urgent' => EmailMessage::query()->where('direction', 'inbound')->where('received_at', '>=', $since)->whereIn('priority', ['high', 'urgent'])->limit(10)->get(['id', 'subject', 'summary'])->toArray(),
                'drafts_waiting' => EmailMessage::query()->where('status', 'draft')->count(),
            ],
            'tenders_open' => Tender::query()->whereIn('status', [TenderStatus::New, TenderStatus::Reviewing, TenderStatus::Bidding])->orderBy('deadline_at')->limit(10)->get(['id', 'title', 'entity', 'deadline_at', 'status'])->toArray(),
            'follow_ups_due' => FollowUp::query()->whereNull('done_at')->where('due_at', '<=', now()->addDay())->orderBy('due_at')->limit(15)->get(['id', 'title', 'due_at'])->toArray(),
            'purchase_requests' => PurchaseRequest::query()->whereNotIn('status', [PurchaseRequestStatus::Received, PurchaseRequestStatus::Cancelled])->get(['id', 'title', 'status', 'needed_by'])->toArray(),
            'contracts_ending_90_days' => Contract::query()->where('status', ContractStatus::Active)->whereBetween('ends_at', [today(), today()->addDays(90)])->get(['id', 'title', 'party_name', 'party_type', 'ends_at'])->toArray(),
            'bank_unreconciled' => BankTransaction::query()->where('status', BankTransactionStatus::Unmatched)->count(),
            'reports_to_review' => Report::query()->where('status', 'draft')->latest()->limit(10)->get(['id', 'type', 'title', 'created_at'])->toArray(),
            'recent_decisions' => KnowledgeItem::query()->where('type', KnowledgeType::Decision)->where('created_at', '>=', now()->subDays(14))->latest()->limit(10)->get(['id', 'title', 'summary', 'created_at'])->toArray(),
        ]);
    }
}
