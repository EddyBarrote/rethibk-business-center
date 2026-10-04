<?php

namespace App\Ai\Budget;

use App\Enums\AgentStatus;
use App\Enums\AuditResult;
use App\Events\BudgetThresholdReached;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\BudgetEvent;
use App\Tenancy\TenantManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Cost limits with automatic cut-off (section 14.3): a warning at 80% of a
 * monthly cap, and at 100% the agent (or every agent, for the tenant cap) is
 * suspended. The per-run cap stops a run mid-way.
 */
final class BudgetGuard
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function budget(): AiBudget
    {
        return AiBudget::for($this->tenants->currentOrFail());
    }

    public function tenantSpent(): float
    {
        return (float) AgentRun::query()->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    public function agentSpent(Agent $agent): float
    {
        return (float) $agent->runs()->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
    }

    /**
     * Refuse to start a run once a monthly cap is used up.
     */
    public function assertCanRun(Agent $agent): void
    {
        $budget = $this->budget();

        if ($budget->tenantMonthly !== null && $this->tenantSpent() >= $budget->tenantMonthly) {
            throw new BudgetExceeded('tenant', 'O orçamento mensal de IA da organização está esgotado.');
        }

        if ($budget->agentMonthly !== null && $this->agentSpent($agent) >= $budget->agentMonthly) {
            $this->suspend($agent, 'Orçamento mensal do agente esgotado.');

            throw new BudgetExceeded('agent', 'O orçamento mensal deste agente está esgotado.');
        }
    }

    /**
     * Called after every model step: stops the run once it costs more than
     * the per-run cap.
     */
    public function assertRunWithinCap(float $runCost): void
    {
        $cap = $this->budget()->perRun;

        if ($cap !== null && $runCost >= $cap) {
            throw new BudgetExceeded('run', sprintf('A execução atingiu o tecto por execução (%.2f USD).', $cap));
        }
    }

    /**
     * Record thresholds crossed by this run and cut off at 100%.
     */
    public function afterRun(AgentRun $run): void
    {
        $budget = $this->budget();
        $agent = $run->agent;
        $ratio = (float) config('agents.budget_warning_ratio', 0.8);

        if ($budget->tenantMonthly !== null) {
            $spent = $this->tenantSpent();

            foreach ([100 => 1.0, (int) round($ratio * 100) => $ratio] as $threshold => $share) {
                if ($spent >= $budget->tenantMonthly * $share) {
                    if ($this->record($run, null, 'tenant', 'tenant', $threshold, $spent, $budget->tenantMonthly) && $threshold === 100) {
                        Agent::query()->where('status', AgentStatus::Active)->each(fn (Agent $a) => $this->suspend($a, 'Orçamento mensal da organização esgotado.'));
                    }

                    break;
                }
            }
        }

        if ($budget->agentMonthly !== null) {
            $spent = $this->agentSpent($agent);

            foreach ([100 => 1.0, (int) round($ratio * 100) => $ratio] as $threshold => $share) {
                if ($spent >= $budget->agentMonthly * $share) {
                    if ($this->record($run, $agent, 'agent', 'agent:'.$agent->id, $threshold, $spent, $budget->agentMonthly) && $threshold === 100) {
                        $this->suspend($agent, 'Orçamento mensal do agente esgotado.');
                    }

                    break;
                }
            }
        }
    }

    /**
     * @return bool whether this is the first time the threshold was crossed this month
     */
    private function record(AgentRun $run, ?Agent $agent, string $scope, string $subject, int $threshold, float $spent, float $cap): bool
    {
        try {
            $event = BudgetEvent::query()->create([
                'agent_id' => $agent?->id,
                'agent_run_id' => $run->id,
                'scope' => $scope,
                'subject_key' => $subject,
                'period' => now()->format('Y-m'),
                'threshold' => $threshold,
                'spent_usd' => $spent,
                'cap_usd' => $cap,
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        AuditLog::record(null, 'budget.threshold', ['scope' => $scope, 'threshold' => $threshold, 'spent_usd' => $spent, 'cap_usd' => $cap], AuditResult::Ok, $agent);
        BudgetThresholdReached::live($event);

        return true;
    }

    private function suspend(Agent $agent, string $reason): void
    {
        if ($agent->status !== AgentStatus::Active) {
            return;
        }

        $agent->forceFill(['status' => AgentStatus::Suspended, 'suspended_reason' => $reason])->save();

        AuditLog::record(null, 'agent.suspended', ['reason' => $reason], AuditResult::Ok, $agent);
    }
}
