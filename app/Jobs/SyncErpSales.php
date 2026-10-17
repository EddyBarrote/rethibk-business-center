<?php

namespace App\Jobs;

use App\Sales\SalesHistory;
use App\Tenancy\TenantAwareJob;

/**
 * Brings a tenant's copy of the ERP sales up to date (docs/DECISOES.md,
 * "Vendas parecidas").
 */
class SyncErpSales extends TenantAwareJob
{
    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(int $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(SalesHistory $sales): void
    {
        $sales->sync();
    }
}
