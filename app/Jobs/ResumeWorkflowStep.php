<?php

namespace App\Jobs;

use App\Models\WorkflowStep;
use App\Tenancy\TenantAwareJob;
use App\Workflows\WorkflowEngine;

/**
 * The end of a "wait" block in a flow.
 */
class ResumeWorkflowStep extends TenantAwareJob
{
    public int $tries = 3;

    public function __construct(int $tenantId, public int $stepId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(WorkflowEngine $engine): void
    {
        $step = WorkflowStep::query()->find($this->stepId);

        if ($step !== null) {
            $engine->resume($step);
        }
    }
}
