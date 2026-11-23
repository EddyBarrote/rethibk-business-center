<?php

namespace App\Http\Controllers\Settings;

use App\Ai\Autonomy\PeopleData;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Runs\RunMetrics;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AiCharge;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Consumo de IA: what the agents spent this month against the caps Rethink
 * sets for the organisation (Admin › Organização), and per agent, for a
 * chosen period, runs, failures, error rate, latency and cost (docs/DECISOES.md,
 * "Latência, taxa de erro e versões das skills"). Read only; raising a cap
 * goes through a budget exception approval.
 */
class UsageController extends Controller
{
    private const PURPOSES = [
        'knowledge.reading' => 'Páginas digitalizadas lidas por IA',
        'knowledge.view' => 'Agentes a ver o original de ficheiros',
    ];

    /** The rolling period, besides the last six months. */
    private const LAST_30_DAYS = '30d';

    public function __invoke(Request $request, BudgetGuard $guard, RunMetrics $metrics): Response
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ViewCosts), 403);

        $months = collect(range(5, 0))->map(fn (int $back) => CarbonImmutable::now()->startOfMonth()->subMonthsNoOverflow($back));
        $selected = $request->validate([
            'period' => ['nullable', 'string', Rule::in([self::LAST_30_DAYS, ...$months->map->format('Y-m')->all()])],
        ])['period'] ?? now()->format('Y-m');
        $month = $months->first(fn (CarbonImmutable $month) => $month->format('Y-m') === $selected);
        [$from, $to] = $month === null ? [CarbonImmutable::now()->subDays(30), null] : [$month, $month->addMonthNoOverflow()];
        $isThisMonth = $selected === now()->format('Y-m');

        $budget = $guard->budget();

        // A confidential agent exists only for people who may manage people (PeopleData):
        // its row is left out, its cost stays in the organisation's totals.
        $hidden = PeopleData::hiddenAgentIds($user);
        $perAgent = $metrics->perAgent($from, $to);
        $agents = Agent::query()->whereIn('id', $perAgent->keys())->get()->keyBy('id');

        return Inertia::render('Settings/Usage', [
            'period' => now()->format('Y-m'),
            'day' => now()->day,
            'days' => now()->daysInMonth,
            'spent' => round($guard->tenantSpent(), 4),
            'cap' => $budget->tenantMonthly === null ? null : round($guard->tenantCap(), 2),
            'extra' => $budget->tenantMonthly === null ? 0 : round($guard->tenantCap() - $budget->tenantMonthly, 2),
            'agent_cap' => $budget->agentMonthly,
            'run_cap' => $budget->perRun,
            'selected' => $selected,
            // The last six months, newest first, then the rolling 30 days.
            'periods' => [...$months->reverse()->map(fn (CarbonImmutable $month) => $month->format('Y-m'))->values()->all(), self::LAST_30_DAYS],
            'agents' => $perAgent
                ->reject(fn (array $row) => in_array($row['agent_id'], $hidden, true))
                ->map(function (array $row) use ($agents, $guard, $budget, $isThisMonth) {
                    $agent = $agents->get($row['agent_id']);

                    return [
                        'id' => $row['agent_id'],
                        'name' => $agent->name ?? 'Agente apagado',
                        'avatar_url' => $agent?->avatarUrl(),
                        'runs' => $row['runs'],
                        'failed' => $row['failed'],
                        'finished' => $row['completed'] + $row['failed'],
                        'error_rate' => $row['error_rate'],
                        'p50_ms' => $row['p50_ms'],
                        'p90_ms' => $row['p90_ms'],
                        'spent' => $row['spent'],
                        // The monthly cap only means something for the month it caps.
                        'cap' => $isThisMonth && $agent !== null && $budget->agentMonthly !== null ? round($guard->agentCap($agent), 2) : null,
                    ];
                })
                ->sortByDesc('spent')
                ->values(),
            'hidden_spent' => round((float) $perAgent->filter(fn (array $row) => in_array($row['agent_id'], $hidden, true))->sum('spent'), 4),
            'months' => $months->map(function (CarbonImmutable $from) {
                $row = AgentRun::query()
                    ->where('created_at', '>=', $from)
                    ->where('created_at', '<', $from->addMonthNoOverflow())
                    ->selectRaw('count(*) as runs, coalesce(sum(cost_usd), 0) as spent')
                    ->first();

                $charges = (float) AiCharge::query()->where('created_at', '>=', $from)->where('created_at', '<', $from->addMonthNoOverflow())->sum('cost_usd');

                return ['period' => $from->format('Y-m'), 'runs' => (int) $row?->getAttribute('runs'), 'spent' => round((float) $row?->getAttribute('spent') + $charges, 4)];
            })->values(),
            // Model calls outside agent runs (docs/DECISOES.md, 14.10.2026), also counted in "spent".
            'other' => AiCharge::query()
                ->where('created_at', '>=', $from)
                ->when($to !== null, fn ($query) => $query->where('created_at', '<', $to))
                ->selectRaw('purpose, count(*) as calls, coalesce(sum(cost_usd), 0) as spent')
                ->groupBy('purpose')
                ->get()
                ->map(fn (AiCharge $row) => [
                    'purpose' => $row->purpose,
                    'label' => self::PURPOSES[$row->purpose] ?? $row->purpose,
                    'calls' => (int) $row->getAttribute('calls'),
                    'spent' => round((float) $row->getAttribute('spent'), 4),
                ])
                ->values(),
        ]);
    }
}
