<?php

namespace App\Support;

use App\Models\Agent;
use Illuminate\Support\Collection;

/**
 * What the entry screen offers to ask an agent (docs/DECISOES.md, "Ecrã de
 * entrada e modo lite"): the four the AI wrote this morning from what the
 * agent does and has been asked ("Sugestões geradas todos os dias"), or,
 * until there are some, the ones its capabilities make possible.
 */
final class AgentSuggestions
{
    public const MAX = 4;

    /**
     * By capability, in the order they are offered: the work an agent is most
     * often there for comes first.
     *
     * @var array<string, string>
     */
    private const BY_CAPABILITY = [
        'finance.month_summary' => 'Resumo financeiro deste mês',
        'erp.invoices.list_receivables' => 'Que faturas estão por receber?',
        'bank.unreconciled' => 'Que movimentos do banco faltam reconciliar?',
        'finance.project_margins' => 'Margens dos projectos em curso',
        'procurement.requests' => 'Que pedidos de compra estão em aberto?',
        'erp.procurement.compare_quotes' => 'Compara as cotações do último pedido',
        'suppliers.scores' => 'Quais são os fornecedores mais bem avaliados?',
        'erp.hr.list_openings' => 'Que vagas temos abertas?',
        'erp.hr.list_leave' => 'Quem está de férias esta semana?',
        'erp.hr.attendance_summary' => 'Resumo das presenças deste mês',
        'clients.sla_status' => 'Que clientes estão fora do prazo de resposta?',
        'erp.leads.search' => 'Que leads novos entraram esta semana?',
        'erp.projects.list' => 'Como estão os projectos activos?',
        'email.summary' => 'Resume os emails importantes de hoje',
        'email.classify' => 'Que emails precisam de resposta?',
        'platform.overview' => 'Como está a empresa esta semana?',
        'approvals.review' => 'Que aprovações estão à espera?',
        'tenders.record' => 'Que concursos estão a decorrer?',
        'reports.draft' => 'Prepara o relatório semanal',
        'tasks.list' => 'Em que tarefas estás a trabalhar?',
        'knowledge.read' => 'O que sabes sobre os nossos procedimentos?',
    ];

    /**
     * @var list<string>
     */
    private const GENERAL = [
        'O que podes fazer por mim?',
        'Resume o que fizeste esta semana',
    ];

    /**
     * @return list<string>
     */
    public static function for(Agent $agent): array
    {
        // Written by the AI each morning (DailySuggestions); a few days old is still better than none.
        $daily = $agent->settings['daily_suggestions'] ?? null;
        if (is_array($daily) && ($daily['items'] ?? []) !== [] && now()->subDays(3)->toDateString() <= (string) ($daily['date'] ?? '')) {
            return array_slice(array_values((array) $daily['items']), 0, self::MAX);
        }

        // Loaded with only the enabled ones (HomeController), or asked for here.
        return self::fromCapabilities($agent->relationLoaded('capabilities')
            ? $agent->capabilities->pluck('key')
            : $agent->capabilities()->wherePivot('enabled', true)->pluck('capabilities.key'));
    }

    /**
     * @param  Collection<int, string>  $keys
     * @return list<string>
     */
    public static function fromCapabilities(Collection $keys): array
    {
        $found = array_values(array_intersect_key(self::BY_CAPABILITY, array_flip($keys->all())));

        return array_slice([...$found, ...self::GENERAL], 0, self::MAX);
    }
}
