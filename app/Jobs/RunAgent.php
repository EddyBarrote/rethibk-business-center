<?php

namespace App\Jobs;

use App\Ai\Runs\AgentRunner;
use App\Models\AgentRun;
use App\Tenancy\TenantAwareJob;

/**
 * Runs a queued agent run (section 6.4).
 */
class RunAgent extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(int $tenantId, public int $runId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(AgentRunner $runner): void
    {
        $run = AgentRun::query()->find($this->runId);

        if ($run !== null) {
            $runner->run($run);
        }
    }
}
