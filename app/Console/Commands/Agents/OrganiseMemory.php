<?php

namespace App\Console\Commands\Agents;

use App\Ai\Memory\MemoryConsolidator;
use App\Jobs\OrganiseAgentMemory;
use App\Models\AgentMemory;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Once, after the move to entries (docs/DECISOES.md, "Memória em entradas",
 * 12.10.2026): each memory with loose notes from the old facts gets them
 * sorted into entries by subject.
 */
#[Signature('agents:organise-memory')]
#[Description('Arruma em entradas por assunto as notas soltas da memória antiga dos agentes')]
class OrganiseMemory extends Command
{
    public function handle(TenantManager $tenants): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($tenants, &$count): void {
            AgentMemory::query()
                ->whereIn('title', array_keys(MemoryConsolidator::LOOSE_NOTES))
                ->where('description', 'like', '%por organizar.')
                ->get(['agent_id', 'user_id'])
                ->unique(fn (AgentMemory $m) => "{$m->agent_id}:{$m->user_id}")
                ->each(function (AgentMemory $m) use ($tenants, &$count): void {
                    OrganiseAgentMemory::dispatch((int) $tenants->id(), $m->agent_id, $m->user_id);
                    $count++;
                });
        });

        $this->components->info("{$count} memória(s) a arrumar.");

        return self::SUCCESS;
    }
}
