<?php

namespace App\Jobs;

use App\Ai\Runs\AgentRunner;
use App\Ai\Runs\StaleRuns;
use App\Models\AgentRun;
use App\Tenancy\TenantAwareJob;
use App\Tenancy\TenantManager;
use Illuminate\Queue\TimeoutExceededException;
use Throwable;

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

    /**
     * Killed at the timeout or crashed: the run is closed, not left "a correr".
     */
    public function failed(?Throwable $e): void
    {
        app(TenantManager::class)->set($this->tenantId);

        $run = AgentRun::query()->find($this->runId);

        if ($run !== null) {
            app(StaleRuns::class)->close($run, $e instanceof TimeoutExceededException
                ? 'A execução foi interrompida por exceder o tempo máximo.'
                : 'A execução foi interrompida antes de terminar.');
        }
    }
}
