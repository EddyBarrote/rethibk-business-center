<?php

use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/**
 * Absolute URL on the tenant's subdomain, so IdentifyTenant resolves it.
 */
function tenantUrl(Tenant $tenant, string $path = '/'): string
{
    return 'http://'.$tenant->slug.'.'.config('tenancy.central_domain').'/'.ltrim($path, '/');
}

/**
 * Run a callback as the given tenant.
 *
 * @template TReturn
 *
 * @param  Closure(Tenant): TReturn  $callback
 * @return TReturn
 */
function asTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantManager::class)->run($tenant, $callback);
}
