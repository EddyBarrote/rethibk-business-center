<?php

namespace App\Console\Commands\Agents;

use App\Ai\Memory\MemoryConsolidator;
use App\Enums\TaskKind;
use App\Jobs\ConsolidateAgentMemory;
use App\Jobs\ConsolidateAgentWork;
use App\Models\Agent;
use App\Models\Task;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Once a day, conversations with something new become the agents' memory
 * (docs/DECISOES.md, realinhamento L7); tasks do it when they are delivered.
 */
#[Signature('agents:consolidate-memory')]
#[Description('Junta à memória dos agentes o que aprenderam nas conversas do dia')]
class ConsolidateMemory extends Command
{
    public function handle(TenantManager $tenants, MemoryConsolidator $memory): int
    {
        $count = 0;
        $work = 0;

        $tenants->eachActive(function () use ($tenants, $memory, &$count, &$work): void {
            Task::query()
                ->where('kind', TaskKind::Chat)
                ->whereNotNull('assignee_agent_id')
                ->whereHas('messages', fn (Builder $m) => $m->whereRaw('task_messages.id > coalesce(tasks.memory_message_id, 0)'))
                ->pluck('id')
                ->each(function (int $id) use ($tenants, &$count): void {
                    ConsolidateAgentMemory::dispatch((int) $tenants->id(), $id);
                    $count++;
                });

            // And the work each agent did with no person (docs/DECISOES.md, "Falhas de contexto").
            Agent::query()->get()
                ->filter(fn (Agent $agent) => $memory->workRuns($agent)->exists())
                ->each(function (Agent $agent) use ($tenants, &$work): void {
                    ConsolidateAgentWork::dispatch((int) $tenants->id(), $agent->id);
                    $work++;
                });
        });

        $this->components->info("{$count} conversa(s) e o trabalho sem pessoa de {$work} agente(s) a consolidar.");

        return self::SUCCESS;
    }
}
