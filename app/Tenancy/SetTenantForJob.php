<?php

namespace App\Tenancy;

use Closure;

final class SetTenantForJob
{
    public function handle(TenantAwareJob $job, Closure $next): mixed
    {
        $tenants = app(TenantManager::class);
        $tenants->set($job->tenantId);

        try {
            return $next($job);
        } finally {
            $tenants->forget();
        }
    }
}
