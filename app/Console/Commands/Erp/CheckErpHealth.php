<?php

namespace App\Console\Commands\Erp;

use App\Jobs\CheckErpConnection;
use App\Models\ErpConnection;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('erp:health-check')]
#[Description('Testa a ligação ao ERP de cada tenant activo (agendado a cada 15 minutos)')]
class CheckErpHealth extends Command
{
    public function handle(TenantManager $tenants): int
    {
        $dispatched = 0;

        $tenants->eachActive(function (Tenant $tenant) use (&$dispatched): void {
            $connection = ErpConnection::query()->currentTenant()->first();

            if ($connection !== null) {
                CheckErpConnection::dispatch($tenant->id, $connection->id);
                $dispatched++;
            }
        });

        $this->components->info("{$dispatched} verificação(ões) despachada(s).");

        return self::SUCCESS;
    }
}
