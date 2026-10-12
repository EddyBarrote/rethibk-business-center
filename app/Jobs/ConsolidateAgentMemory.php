<?php

namespace App\Jobs;

use App\Ai\Memory\MemoryConsolidator;
use App\Models\Task;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Turns what is new in a task or conversation into the agent's memory (realinhamento L7).
 */
class ConsolidateAgentMemory extends TenantAwareJob implements ShouldBeUnique
{
    public int $tries = 2;

    /** One pass per thread at a time: a second one would only read what the first already read. */
    public int $uniqueFor = 600;

    public function __construct(int $tenantId, public int $taskId)
    {
        $this->tenantId = $tenantId;
    }

    public function uniqueId(): string
    {
        return "{$this->tenantId}:{$this->taskId}";
    }

    public function handle(MemoryConsolidator $memory): void
    {
        $task = Task::query()->with('assigneeAgent')->find($this->taskId);

        if ($task !== null) {
            $memory->consolidate($task);
        }
    }
}
