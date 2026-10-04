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

/**
 * Point the fake ERP at a fresh state file, for this process and for the
 * stdio server processes the rethink_erp client starts (they inherit the env).
 */
function freshFakeErp(): string
{
    $path = sys_get_temp_dir().'/fake-erp-'.bin2hex(random_bytes(6)).'.json';

    putenv("ERP_FAKE_STORAGE_PATH={$path}");
    config(['erp.fake.storage_path' => $path, 'erp.transport' => 'local']);

    return $path;
}

/**
 * Absolute URL on the super admin host.
 */
function adminUrl(string $path = '/'): string
{
    return 'http://'.config('tenancy.admin_domain').'/'.ltrim($path, '/');
}
