<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Enums\TenderStatus;
use App\Models\EmailMessage;
use App\Models\Tender;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Figures for the daily triage summary: what arrived, what is still open,
 * and the deadlines coming up.
 */
final class InboxSummary extends LocalCapability
{
    public function key(): string
    {
        return 'email.summary';
    }

    public function name(): string
    {
        return 'Resumo da caixa';
    }

    public function description(): string
    {
        return 'Números da caixa num período: emails por categoria e prioridade, sem triagem, leads criadas e prazos a vencer.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->integer()->min(1)->max(24 * 31)->description('Janela em horas; por omissão 24.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $since = now()->subHours((int) ($arguments['hours'] ?? 24));
        $inbound = EmailMessage::query()->triage()->where('direction', 'inbound')->where('received_at', '>=', $since);

        return CapabilityResult::data([
            'since' => $since->toIso8601String(),
            'received' => (clone $inbound)->count(),
            'by_category' => (clone $inbound)->selectRaw('classification, count(*) as total')->groupBy('classification')->pluck('total', 'classification'),
            'urgent_or_high' => (clone $inbound)->whereIn('priority', ['high', 'urgent'])->get(['id', 'subject', 'from_address', 'summary'])->toArray(),
            'untriaged' => EmailMessage::query()->triage()->where('direction', 'inbound')->whereNull('classification')->count(),
            'leads_created' => (clone $inbound)->whereNotNull('erp_lead_id')->count(),
            'drafts_waiting' => EmailMessage::query()->triage()->where('status', 'draft')->count(),
            'deadlines_next_7_days' => [
                'emails' => EmailMessage::query()->triage()->whereBetween('deadline_at', [now(), now()->addDays(7)])->orderBy('deadline_at')->get(['id', 'subject', 'deadline_at'])->toArray(),
                'tenders' => Tender::query()->whereBetween('deadline_at', [now(), now()->addDays(7)])->whereNotIn('status', [TenderStatus::Discarded, TenderStatus::Lost, TenderStatus::Submitted])->orderBy('deadline_at')->get(['id', 'title', 'entity', 'deadline_at', 'status'])->toArray(),
            ],
        ]);
    }
}
