<?php

namespace App\Http\Controllers;

use App\Ai\Budget\BudgetGuard;
use App\Enums\AgentStatus;
use App\Enums\Permission;
use App\Enums\RunStatus;
use App\Http\Presenters\Present;
use App\Insights\IssueDetector;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Briefing;
use App\Models\Capability;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, IssueDetector $issues, BudgetGuard $budget): Response
    {
        $user = $this->user($request);

        return Inertia::render('Dashboard', [
            // Requests written for agents name tools by key; the screen shows their names.
            'tool_names' => Capability::query()->pluck('name', 'key'),
            'approvals' => Approval::query()->visibleTo($user)->pending()->with(Present::APPROVAL_RELATIONS)->latest('id')->limit(5)->get()
                ->map(fn (Approval $approval) => Present::approval($approval, $user->can('decide', $approval))),
            'agents' => Agent::query()->where('status', '!=', AgentStatus::Draft)->with(['department:id,name', 'reportsTo:id,name'])->orderBy('name')->get()
                ->map(fn (Agent $agent) => Present::agent($agent)),
            'briefing' => ($briefing = Briefing::query()->where('for_user_id', $user->id)->latest('id')->first()) !== null
                ? [...BriefingController::present($briefing), 'content' => $briefing->content]
                : null,
            'issues' => fn () => $user->isManager() ? $issues->detect() : [],
            'live' => AgentRun::query()->with(['agent:id,name', 'requestedBy:id,name'])
                ->whereIn('status', [RunStatus::Queued, RunStatus::Running, RunStatus::AwaitingApproval])
                ->latest('id')->limit(4)->get()
                ->map(fn (AgentRun $run) => Present::run($run)),
            'metrics' => fn () => $this->metrics($budget, $user->hasPermission(Permission::ViewCosts)),
            'activity' => fn () => $this->activity($user->hasPermission(Permission::ViewCosts)),
            'can_view_costs' => $user->hasPermission(Permission::ViewCosts),
            'runs' => AgentRun::query()->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->limit(8)->get()
                ->map(fn (AgentRun $run) => Present::run($run)),
        ]);
    }

    /**
     * Headline numbers for the dashboard cards.
     *
     * @return array<string, mixed>
     */
    private function metrics(BudgetGuard $budget, bool $costs): array
    {
        $agents = Agent::query()->where('status', '!=', AgentStatus::Draft)->get(['id', 'status']);
        $byStatus = AgentRun::query()
            ->whereIn('status', [RunStatus::Queued, RunStatus::Running, RunStatus::AwaitingApproval])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'agents_active' => $agents->where('status', AgentStatus::Active)->count(),
            'agents_suspended' => $agents->where('status', AgentStatus::Suspended)->count(),
            'runs_running' => (int) ($byStatus[RunStatus::Running->value] ?? 0) + (int) ($byStatus[RunStatus::Queued->value] ?? 0),
            'runs_waiting' => (int) ($byStatus[RunStatus::AwaitingApproval->value] ?? 0),
            'runs_failed_week' => AgentRun::query()->where('status', RunStatus::Failed)->where('created_at', '>=', now()->subDays(7))->count(),
            // What AI costs is for those the matrix lets see it.
            'month_spend_usd' => $costs ? round($budget->tenantSpent(), 4) : null,
            'month_budget_usd' => $costs ? $budget->budget()->tenantMonthly : null,
        ];
    }

    /**
     * Runs and spend per day for the last 14 days, oldest first.
     *
     * @return list<array{date: string, completed: int, failed: int, waiting: int, other: int, cost_usd: float}>
     */
    private function activity(bool $costs): array
    {
        $from = now()->subDays(13)->startOfDay();
        $runs = AgentRun::query()->where('created_at', '>=', $from)->get(['status', 'cost_usd', 'created_at']);
        $days = [];

        for ($day = $from->copy(); $day->lte(now()); $day->addDay()) {
            $days[$day->toDateString()] = ['date' => $day->toDateString(), 'completed' => 0, 'failed' => 0, 'waiting' => 0, 'other' => 0, 'cost_usd' => 0.0];
        }

        foreach ($runs as $run) {
            $key = Carbon::parse($run->created_at)->toDateString();

            if (! isset($days[$key])) {
                continue;
            }

            $bucket = match ($run->status) {
                RunStatus::Completed => 'completed',
                RunStatus::Failed => 'failed',
                RunStatus::AwaitingApproval => 'waiting',
                default => 'other',
            };
            $days[$key][$bucket]++;
            $days[$key]['cost_usd'] += $costs ? (float) $run->cost_usd : 0.0;
        }

        return array_values($days);
    }
}
