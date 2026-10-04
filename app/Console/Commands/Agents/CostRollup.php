<?php

namespace App\Console\Commands\Agents;

use App\Ai\Budget\BudgetGuard;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Section 14.2: the day's AI cost per agent and the month so far against
 * the budget, written to the audit log so it survives price changes.
 */
#[Signature('agents:cost-rollup {--date= : Dia (AAAA-MM-DD); por omissão hoje}')]
#[Description('Fecha o custo de IA do dia por agente e por tenant (diário às 23:50)')]
class CostRollup extends Command
{
    public function handle(TenantManager $tenants, BudgetGuard $budget): int
    {
        $day = $this->option('date') ? now()->parse((string) $this->option('date')) : today();

        $tenants->eachActive(function (Tenant $tenant) use ($budget, $day): void {
            $runs = AgentRun::query()->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
            $perAgent = (clone $runs)->selectRaw('agent_id, count(*) as runs, sum(cost_usd) as cost, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens')->groupBy('agent_id')->get();
            $names = Agent::query()->pluck('name', 'id');

            $payload = [
                'date' => $day->toDateString(),
                'cost_usd' => round((float) (clone $runs)->sum('cost_usd'), 4),
                'month_to_date_usd' => round($budget->tenantSpent(), 4),
                'monthly_cap_usd' => $budget->budget()->tenantMonthly,
                'agents' => $perAgent->map(fn (AgentRun $row) => [
                    'agent' => $names[$row->agent_id] ?? $row->agent_id,
                    'runs' => (int) $row->getAttribute('runs'),
                    'cost_usd' => round((float) $row->getAttribute('cost'), 4),
                    'tokens' => (int) $row->getAttribute('input_tokens') + (int) $row->getAttribute('output_tokens'),
                ])->values()->all(),
            ];

            AuditLog::record(null, 'ai.cost_rollup', $payload);
            $this->components->twoColumnDetail($tenant->slug, '$'.number_format($payload['cost_usd'], 4).' hoje · $'.number_format($payload['month_to_date_usd'], 2).' no mês');
        });

        return self::SUCCESS;
    }
}
