<?php

use App\Enums\ErpConnectionStatus;
use App\Enums\ErpTransport;
use App\Models\AuditLog;
use App\Models\ErpConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create();
    [$this->admin, $this->member] = asTenant($this->tenant, fn () => [
        User::factory()->admin()->create(),
        User::factory()->create(),
    ]);
});

afterEach(fn () => @unlink($this->store));

$valid = [
    'name' => 'Rethink ERP',
    'transport' => 'web',
    'base_url' => 'https://erp.example.test/mcp',
    'token' => 'segredo-123',
    'enabled' => true,
];

it('keeps the ERP settings away from members', function () use ($valid) {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/erp'))->assertForbidden();
    $this->actingAs($this->member)->put(tenantUrl($this->tenant, 'settings/erp'), $valid)->assertForbidden();
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'settings/erp/test'))->assertForbidden();
});

it('stores the connection with an encrypted token that never reaches the page', function () use ($valid) {
    $this->actingAs($this->admin)->put(tenantUrl($this->tenant, 'settings/erp'), $valid)->assertSessionHasNoErrors();

    $raw = DB::table('erp_connections')->sole();
    expect($raw->credentials)->not->toContain('segredo-123')
        ->and($raw->status)->toBe('untested');

    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, 'settings/erp'))
        ->assertOk()
        ->assertDontSee('segredo-123')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Erp')
            ->where('connection.has_token', true)
            ->where('connection.base_url', 'https://erp.example.test/mcp')
            ->where('calls.0.action', 'erp.connection_updated'));
});

it('keeps the stored token when the field is left blank', function () use ($valid) {
    $this->actingAs($this->admin)->put(tenantUrl($this->tenant, 'settings/erp'), $valid);
    $this->actingAs($this->admin)->put(tenantUrl($this->tenant, 'settings/erp'), [...$valid, 'name' => 'ERP', 'token' => '']);

    $connection = asTenant($this->tenant, fn () => ErpConnection::query()->sole());

    expect($connection->decryptedToken())->toBe('segredo-123')
        ->and($connection->name)->toBe('ERP');
});

it('requires an address for the web transport', function () use ($valid) {
    $this->actingAs($this->admin)
        ->put(tenantUrl($this->tenant, 'settings/erp'), [...$valid, 'base_url' => ''])
        ->assertSessionHasErrors('base_url');
});

it('disables a connection so agents stop using it', function () use ($valid) {
    $this->actingAs($this->admin)->put(tenantUrl($this->tenant, 'settings/erp'), [...$valid, 'enabled' => false]);

    expect(asTenant($this->tenant, fn () => [
        ErpConnection::query()->sole()->status,
        ErpConnection::query()->currentTenant()->exists(),
    ]))->toBe([ErpConnectionStatus::Disabled, false]);
});

it('tests the connection against the fake ERP', function () {
    $this->actingAs($this->admin)->put(tenantUrl($this->tenant, 'settings/erp'), [
        'name' => 'Servidor falso',
        'transport' => 'local',
        'enabled' => true,
    ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(tenantUrl($this->tenant, 'settings/erp/test'))
        ->assertSessionHas('success', 'Ligação ao ERP a funcionar: 38 ferramentas disponíveis.');

    $connection = asTenant($this->tenant, fn () => ErpConnection::query()->sole());

    expect($connection->transport)->toBe(ErpTransport::Local)
        ->and($connection->status)->toBe(ErpConnectionStatus::Ok)
        ->and(asTenant($this->tenant, fn () => AuditLog::query()->where('action', 'erp.connection_test')->sole()->actor_id))->toBe($this->admin->id);
});
