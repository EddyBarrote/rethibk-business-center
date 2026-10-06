<?php

namespace App\Jobs;

use App\Ai\Memory\MemoryConsolidator;
use App\Models\Task;
use App\Tenancy\TenantAwareJob;

/**
 * Turns what is new in a task or conversation into the agent's memory (realinhamento L7).
 */
class ConsolidateAgentMemory extends TenantAwareJob
{
    public int $tries = 2;

    public function __construct(int $tenantId, public int $taskId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(MemoryConsolidator $memory): void
    {
        $task = Task::query()->with('assigneeAgent')->find($this->taskId);

        if ($task !== null) {
            $memory->consolidate($task);
        }
    }
}
