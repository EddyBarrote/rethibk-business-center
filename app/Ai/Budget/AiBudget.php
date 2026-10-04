<?php

namespace App\Ai\Budget;

use App\Models\Tenant;

/**
 * The tenant's AI caps, kept in the tenant profile (tenants.settings.ai_budget,
 * docs/DECISOES.md). Null means no cap. Values in USD.
 */
final readonly class AiBudget
{
    public function __construct(
        public ?float $tenantMonthly,
        public ?float $agentMonthly,
        public ?float $perRun,
    ) {}

    public static function for(Tenant $tenant): self
    {
        return self::fromArray($tenant->settings['ai_budget'] ?? []);
    }

    /**
     * @param  array<string, mixed>  $budget
     */
    public static function fromArray(array $budget): self
    {
        return new self(
            self::amount($budget['tenant_monthly_usd'] ?? null),
            self::amount($budget['agent_monthly_usd'] ?? null),
            self::amount($budget['run_usd'] ?? null),
        );
    }

    /**
     * @return array{tenant_monthly_usd: float|null, agent_monthly_usd: float|null, run_usd: float|null}
     */
    public function toArray(): array
    {
        return [
            'tenant_monthly_usd' => $this->tenantMonthly,
            'agent_monthly_usd' => $this->agentMonthly,
            'run_usd' => $this->perRun,
        ];
    }

    private static function amount(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }
}
