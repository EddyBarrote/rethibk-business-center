<?php

namespace App\Tenancy;

use Closure;

final class SetTenantForJob
{
    public function handle(TenantAwareJob $job, Closure $next): mixed
    {
        // run() restores whatever was set before: nothing on a worker, the
        // caller's tenant when a job runs synchronously.
        return app(TenantManager::class)->run($job->tenantId, fn () => $next($job));
    }
}
