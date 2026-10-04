<?php

namespace App\Jobs;

use App\Erp\ErpGateway;
use App\Models\ErpConnection;
use App\Tenancy\TenantAwareJob;

/**
 * Tests a tenant's ERP connection and stores the outcome (section 14.2).
 */
class CheckErpConnection extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(int $tenantId, public int $connectionId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(ErpGateway $gateway): void
    {
        $connection = ErpConnection::query()->currentTenant()->find($this->connectionId);

        if ($connection !== null) {
            $gateway->test($connection);
        }
    }
}
