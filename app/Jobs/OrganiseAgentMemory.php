<?php

namespace App\Jobs;

use App\Ai\Memory\MemoryConsolidator;
use App\Models\Agent;
use App\Models\User;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Sorts the loose notes of one memory (an agent with a person, or alone) into
 * entries by subject (docs/DECISOES.md, "Memória em entradas", 12.10.2026).
 */
class OrganiseAgentMemory extends TenantAwareJob implements ShouldBeUnique
{
    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(int $tenantId, public int $agentId, public ?int $userId)
    {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->agentId}:".($this->userId ?? 'agent');
    }

    public function handle(MemoryConsolidator $memory): void
    {
        $agent = Agent::query()->find($this->agentId);

        if ($agent !== null) {
            $memory->organise($agent, $this->userId !== null ? User::query()->find($this->userId) : null);
        }
    }
}
