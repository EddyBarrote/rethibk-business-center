<?php

use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Tests\Fixtures\ProbeTenantJob;

it('resolves the tenant from its subdomain', function () {
    $tenant = Tenant::factory()->create(['slug' => 'micomoc', 'name' => 'MICOMOC']);

    $this->get('http://micomoc.localhost/login')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/Login')->where('tenant.name', 'MICOMOC'));
});

it('resolves the tenant from its own domain', function () {
    Tenant::factory()->create(['slug' => 'micomoc', 'domain' => 'agentes.micomoc.co.mz', 'name' => 'MICOMOC']);

    $this->get('http://agentes.micomoc.co.mz/login')->assertOk();
});

it('returns 404 for unknown hosts', function (string $url) {
    Tenant::factory()->create(['slug' => 'micomoc']);

    $this->get($url)->assertNotFound();
})->with([
    'unknown subdomain' => 'http://outro.localhost/login',
    'bare central domain' => 'http://localhost/login',
    'nested subdomain' => 'http://a.micomoc.localhost/login',
    'unrelated domain' => 'http://example.com/login',
]);

it('returns 403 for a suspended tenant', function () {
    $tenant = Tenant::factory()->suspended()->create();

    $this->get(tenantUrl($tenant, 'login'))->assertForbidden();
});

it('sets the tenant for a queued job and forgets it afterwards', function () {
    $tenant = Tenant::factory()->create();
    asTenant($tenant, fn () => User::factory()->count(2)->create());

    dispatch_sync(new ProbeTenantJob($tenant->id));

    expect(ProbeTenantJob::$seenTenant)->toBe($tenant->id)
        ->and(ProbeTenantJob::$seenUsers)->toBe(2)
        ->and(app(TenantManager::class)->check())->toBeFalse();
});

it('forgets the job tenant even when the job fails', function () {
    $tenant = Tenant::factory()->create();

    expect(fn () => dispatch_sync(new ProbeTenantJob($tenant->id, fail: true)))->toThrow(RuntimeException::class);
    expect(app(TenantManager::class)->check())->toBeFalse();
});

it('iterates only active tenants for scheduled work', function () {
    $active = Tenant::factory()->count(2)->create();
    Tenant::factory()->suspended()->create();

    $seen = [];
    app(TenantManager::class)->eachActive(function (Tenant $tenant) use (&$seen) {
        $seen[] = app(TenantManager::class)->id();
    });

    expect($seen)->toBe($active->pluck('id')->all())
        ->and(app(TenantManager::class)->check())->toBeFalse();
});
