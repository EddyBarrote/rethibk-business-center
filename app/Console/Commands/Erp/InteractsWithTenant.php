<?php

namespace App\Console\Commands\Erp;

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Closure;

/**
 * Runs a console command as the tenant named by its {tenant} argument.
 */
trait InteractsWithTenant
{
    /**
     * @param  Closure(Tenant): int  $callback
     */
    protected function asTenant(Closure $callback): int
    {
        $slug = (string) $this->argument('tenant');
        $tenant = Tenant::query()->where('slug', $slug)->first();

        if ($tenant === null) {
            $this->components->error("Tenant [{$slug}] não existe.");

            return self::FAILURE;
        }

        return app(TenantManager::class)->run($tenant, $callback);
    }
}
