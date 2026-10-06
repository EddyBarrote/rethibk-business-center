<?php

namespace App\Http\Controllers\Settings;

use App\Ai\Budget\BudgetGuard;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consumo de IA: what the agents spent this month against the caps Rethink
 * sets for the organisation (Admin › Organização). Read only; raising a cap
 * goes through a budget exception approval.
 */
class UsageController extends Controller
{
    public function __invoke(Request $request, BudgetGuard $guard): Response
    {
        abort_unless($this->user($request)->hasPermission(Permission::ViewCosts), 403);

        $budget = $guard->budget();
        $start = now()->startOfMonth();

        $perAgent = AgentRun::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('agent_id, count(*) as runs, coalesce(sum(cost_usd), 0) as spent')
            ->groupBy('agent_id')
            ->get();
        $agents = Agent::query()->whereIn('id', $perAgent->pluck('agent_id'))->get()->keyBy('id');

        $months = collect(range(5, 0))->map(function (int $back) {
            $from = now()->startOfMonth()->subMonths($back);
            $row = AgentRun::query()
                ->where('created_at', '>=', $from)
                ->where('created_at', '<', $from->copy()->addMonth())
                ->selectRaw('count(*) as runs, coalesce(sum(cost_usd), 0) as spent')
                ->first();

            return ['period' => $from->format('Y-m'), 'runs' => (int) $row?->getAttribute('runs'), 'spent' => round((float) $row?->getAttribute('spent'), 4)];
        });

        return Inertia::render('Settings/Usage', [
            'period' => now()->format('Y-m'),
            'day' => now()->day,
            'days' => now()->daysInMonth,
            'spent' => round($guard->tenantSpent(), 4),
            'cap' => $budget->tenantMonthly === null ? null : round($guard->tenantCap(), 2),
            'extra' => $budget->tenantMonthly === null ? 0 : round($guard->tenantCap() - $budget->tenantMonthly, 2),
            'agent_cap' => $budget->agentMonthly,
            'run_cap' => $budget->perRun,
            'agents' => $perAgent
                ->map(function (AgentRun $row) use ($agents, $guard, $budget) {
                    $agent = $agents->get($row->agent_id);

                    return [
                        'id' => $row->agent_id,
                        'name' => $agent->name ?? 'Agente apagado',
                        'avatar_url' => $agent?->avatarUrl(),
                        'runs' => (int) $row->getAttribute('runs'),
                        'spent' => round((float) $row->getAttribute('spent'), 4),
                        'cap' => $agent !== null && $budget->agentMonthly !== null ? round($guard->agentCap($agent), 2) : null,
                    ];
                })
                ->sortByDesc('spent')
                ->values(),
            'months' => $months,
        ]);
    }
}
