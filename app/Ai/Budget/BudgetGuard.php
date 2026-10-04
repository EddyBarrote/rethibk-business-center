<?php

namespace App\Ai\Budget;

use App\Ai\Autonomy\GateDecision;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Runs\ApprovalService;
use App\Enums\AgentStatus;
use App\Enums\AuditResult;
use App\Enums\AutonomyLevel;
use App\Events\BudgetThresholdReached;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\BudgetEvent;
use App\Models\Capability;
use App\Tenancy\TenantManager;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Cost limits with automatic cut-off (section 14.3): a warning at 80% of a
 * monthly cap, and at 100% the agent (or every agent, for the tenant cap) is
 * suspended. The per-run cap stops a run mid-way.
 */
final class BudgetGuard
{
    private const AGENT_REASON = 'Orçamento mensal do agente esgotado.';

    private const TENANT_REASON = 'Orçamento mensal da organização esgotado.';

    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * The organisation's monthly cap plus any exception approved this month.
     */
    public function tenantCap(): float
    {
        return (float) $this->budget()->tenantMonthly + $this->extra('tenant');
    }

    public function agentCap(Agent $agent): float
    {
        return (float) $this->budget()->agentMonthly + $this->extra('agent:'.$agent->id);
    }

    /**
     * Apply an approved budget exception: raise the cap for the period and
     * wake the agents the cap had stopped.
     *
     * @return list<string> names of the agents reactivated
     */
    public function grantExtra(string $scope, ?int $agentId, float $amount, string $period): array
    {
        $tenant = $this->tenants->currentOrFail();
        $key = $scope === 'tenant' ? 'tenant' : 'agent:'.$agentId;
        $settings = $tenant->settings ?? [];
        $settings['ai_budget_extra'][$period][$key] = round((float) ($settings['ai_budget_extra'][$period][$key] ?? 0) + $amount, 4);
        $tenant->forceFill(['settings' => $settings])->save();

        AuditLog::record(null, 'budget.extra_granted', ['scope' => $key, 'extra_usd' => $amount, 'period' => $period]);

        $suspended = Agent::query()
            ->where('status', AgentStatus::Suspended)
            ->where('suspended_reason', $scope === 'tenant' ? self::TENANT_REASON : self::AGENT_REASON)
            ->when($scope === 'agent', fn ($q) => $q->whereKey($agentId))
            ->get();

        $suspended->each(function (Agent $agent) {
            $agent->forceFill(['status' => AgentStatus::Active, 'suspended_reason' => null])->save();
            AuditLog::record(null, 'agent.reactivated', ['reason' => 'Excepção de orçamento aprovada.'], AuditResult::Ok, $agent);
        });

        return $suspended->pluck('name')->values()->all();
    }

    private function extra(string $key): float
    {
        $tenant = $this->tenants->currentOrFail();

        return (float) ($tenant->settings['ai_budget_extra'][now()->format('Y-m')][$key] ?? 0);
    }

    /**
     * Ask a person whether to allow more spend this month (Paperclip's budget
     * override approval). Half the cap again by default, at least 1 USD.
     */
    private function requestOverride(AgentRun $run, string $scope, float $cap): void
    {
        $capability = Capability::query()->where('key', 'budget.override')->first();

        if ($capability === null) {
            return;
        }

        $arguments = [
            'scope' => $scope,
            'agent_id' => $scope === 'agent' ? $run->agent_id : null,
            'extra_usd' => max(1.0, round($cap * 0.5, 2)),
            'period' => now()->format('Y-m'),
        ];

        app(ApprovalService::class)->request($capability, $arguments, new GateDecision(false, AutonomyLevel::ExecuteAndReport, 'aumentar o orçamento de IA é sempre uma decisão humana'), new CapabilityContext($run->agent, $run));
    }

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

        if ($budget->tenantMonthly !== null && $this->tenantSpent() >= $this->tenantCap()) {
            throw new BudgetExceeded('tenant', 'O orçamento mensal de IA da organização está esgotado.');
        }

        if ($budget->agentMonthly !== null && $this->agentSpent($agent) >= $this->agentCap($agent)) {
            $this->suspend($agent, self::AGENT_REASON);

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
            $cap = $this->tenantCap();

            foreach ([100 => 1.0, (int) round($ratio * 100) => $ratio] as $threshold => $share) {
                if ($spent >= $cap * $share) {
                    if ($this->record($run, null, 'tenant', 'tenant', $threshold, $spent, $cap) && $threshold === 100) {
                        Agent::query()->where('status', AgentStatus::Active)->each(fn (Agent $a) => $this->suspend($a, self::TENANT_REASON));
                        $this->requestOverride($run, 'tenant', $cap);
                    }

                    break;
                }
            }
        }

        if ($budget->agentMonthly !== null) {
            $spent = $this->agentSpent($agent);
            $cap = $this->agentCap($agent);

            foreach ([100 => 1.0, (int) round($ratio * 100) => $ratio] as $threshold => $share) {
                if ($spent >= $cap * $share) {
                    if ($this->record($run, $agent, 'agent', 'agent:'.$agent->id, $threshold, $spent, $cap) && $threshold === 100) {
                        $this->suspend($agent, self::AGENT_REASON);
                        $this->requestOverride($run, 'agent', $cap);
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
