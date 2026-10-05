<?php

namespace App\Console\Commands\Agents;

use App\Enums\TaskKind;
use App\Jobs\ConsolidateAgentMemory;
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
    public function handle(TenantManager $tenants): int
    {
        $count = 0;

        $tenants->eachActive(function () use ($tenants, &$count): void {
            Task::query()
                ->where('kind', TaskKind::Chat)
                ->whereNotNull('assignee_agent_id')
                ->whereHas('messages', fn (Builder $m) => $m->whereRaw('task_messages.id > coalesce(tasks.memory_message_id, 0)'))
                ->pluck('id')
                ->each(function (int $id) use ($tenants, &$count): void {
                    ConsolidateAgentMemory::dispatch((int) $tenants->id(), $id);
                    $count++;
                });
        });

        $this->components->info("{$count} conversa(s) a consolidar.");

        return self::SUCCESS;
    }
}
