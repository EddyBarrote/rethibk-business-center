<?php

namespace App\Console\Commands\Erp;

use App\Erp\ErpDialect;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Jobs\SyncErpSales as SyncJob;
use App\Models\ErpConnection;
use App\Models\Tenant;
use App\Sales\SalesHistory;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('erp:sync-sales {tenant? : Slug do tenant; corre já, em vez de pôr na fila}')]
#[Description('Copia as facturas, cotações e artigos do ERP, linha a linha, para os agentes procurarem vendas parecidas')]
class SyncErpSales extends Command
{
    public function handle(TenantManager $tenants, ErpGateway $erp, SalesHistory $sales): int
    {
        $slug = $this->argument('tenant');

        if (is_string($slug) && $slug !== '') {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if ($tenant === null) {
                $this->components->error("Não há tenant {$slug}.");

                return self::FAILURE;
            }

            return $tenants->run($tenant, function () use ($sales): int {
                try {
                    $counts = $sales->sync();
                } catch (ErpException $e) {
                    $this->components->error($e->getMessage());

                    return self::FAILURE;
                }

                $this->components->info("{$counts['invoice']} facturas, {$counts['estimate']} cotações e {$counts['item']} artigos; {$counts['read']} lidos agora, {$counts['removed']} retirados.");

                return self::SUCCESS;
            });
        }

        $dispatched = 0;

        $tenants->eachActive(function (Tenant $tenant) use ($erp, &$dispatched): void {
            if (ErpConnection::query()->currentTenant()->exists() && $erp->dialect() === ErpDialect::Perfex) {
                SyncJob::dispatch($tenant->id);
                $dispatched++;
            }
        });

        $this->components->info("{$dispatched} cópia(s) despachada(s).");

        return self::SUCCESS;
    }
}
