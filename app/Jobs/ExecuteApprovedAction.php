<?php

namespace App\Jobs;

use App\Ai\Runs\ApprovalService;
use App\Models\Approval;
use App\Tenancy\TenantAwareJob;

/**
 * Runs an action a human approved. ApprovalService::execute() runs it at
 * most once, so retries are safe.
 */
class ExecuteApprovedAction extends TenantAwareJob
{
    public int $tries = 1;

    public function __construct(int $tenantId, public int $approvalId)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('agents-high');
    }

    public function handle(ApprovalService $approvals): void
    {
        $approval = Approval::query()->find($this->approvalId);

        if ($approval !== null) {
            $approvals->execute($approval);
        }
    }
}
