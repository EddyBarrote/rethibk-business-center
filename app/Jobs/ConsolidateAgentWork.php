<?php

namespace App\Jobs;

use App\Ai\Memory\MemoryConsolidator;
use App\Models\Agent;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Turns what an agent did with no person (routines with no recipient, email,
 * follow-ups) into its work memory (docs/DECISOES.md, "Falhas de contexto").
 */
class ConsolidateAgentWork extends TenantAwareJob implements ShouldBeUnique
{
    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(int $tenantId, public int $agentId)
    {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->agentId}";
    }

    public function handle(MemoryConsolidator $memory): void
    {
        $agent = Agent::query()->find($this->agentId);

        if ($agent !== null) {
            $memory->consolidateWork($agent);
        }
    }
}
