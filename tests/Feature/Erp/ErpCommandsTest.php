<?php

use App\Jobs\CheckErpConnection;
use App\Models\AuditLog;
use App\Models\ErpConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create(['slug' => 'micomoc']);
});

afterEach(fn () => @unlink($this->store));

it('proves E01: lists the tools, calls a read and a write, and both are audited', function () {
    $this->artisan('erp:smoke', ['tenant' => 'micomoc'])
        ->expectsOutputToContain('E01 confirmada')
        ->assertSuccessful();

    $tools = asTenant($this->tenant, fn () => AuditLog::query()->where('action', 'erp.tool_call')->pluck('payload')->pluck('tool')->all());

    expect($tools)->toBe(['crm.search_accounts', 'leads.create']);
});

it('calls one tool from the console', function () {
    $this->artisan('erp:call', ['tenant' => 'micomoc', 'tool' => 'projects.get', 'arguments' => '{"project_id":"PRJ-0001"}'])
        ->expectsOutputToContain('Remodelação da ala norte')
        ->assertSuccessful();

    $this->artisan('erp:call', ['tenant' => 'micomoc', 'tool' => 'projects.get', 'arguments' => '{"project_id":"PRJ-9"}'])
        ->assertFailed();
});

it('lists the tools from the console', function () {
    $this->artisan('erp:tools', ['tenant' => 'micomoc'])
        ->expectsOutputToContain('27 ferramentas')
        ->assertSuccessful();
});

it('dispatches one health check per active tenant with a connection', function () {
    Queue::fake();

    $suspended = Tenant::factory()->suspended()->create();
    $without = Tenant::factory()->create();
    asTenant($this->tenant, fn () => ErpConnection::factory()->local()->create());
    asTenant($suspended, fn () => ErpConnection::factory()->local()->create());

    $this->artisan('erp:health-check')->assertSuccessful();

    Queue::assertPushed(CheckErpConnection::class, 1);
    Queue::assertPushed(CheckErpConnection::class, fn (CheckErpConnection $job) => $job->tenantId === $this->tenant->id);
});
