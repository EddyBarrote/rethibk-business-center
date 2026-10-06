<?php

use App\Enums\ActorType;
use App\Enums\AuditResult;
use App\Enums\ErpConnectionStatus;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Erp\Exceptions\ErpNotConfiguredException;
use App\Models\AuditLog;
use App\Models\ErpConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\NoTenantException;

// These tests talk to the real fake ERP over stdio, as the platform does.

beforeEach(function () {
    $this->store = freshFakeErp();
    $this->tenant = Tenant::factory()->create();
    $this->gateway = app(ErpGateway::class);
});

afterEach(fn () => @unlink($this->store));

it('lists the ERP tools over MCP', function () {
    $tools = asTenant($this->tenant, fn () => $this->gateway->tools());

    expect($tools)->toHaveCount(38)
        ->and(collect($tools)->firstWhere('name', 'crm.get_account')->readOnly)->toBeTrue()
        ->and(collect($tools)->firstWhere('name', 'leads.create')->readOnly)->toBeFalse();
});

it('audits a read and a write with the tenant, actor, arguments and outcome', function () {
    $user = asTenant($this->tenant, fn () => User::factory()->create());

    asTenant($this->tenant, function () use ($user) {
        $read = $this->gateway->call('crm.get_account', ['account_id' => 'ACC-0001'], $user);
        $write = $this->gateway->call('crm.create_contact', ['account_id' => 'ACC-0001', 'name' => 'Ana Cossa', 'idempotency_key' => 'c-1'], $user);

        expect($read->ok)->toBeTrue()
            ->and($read->data['account']['nuit'])->toBe('400118274')
            ->and($write->ok)->toBeTrue()
            ->and($write->data['contact']['id'])->toBe('CNT-0006');
    });

    $logs = asTenant($this->tenant, fn () => AuditLog::query()->where('action', 'erp.tool_call')->orderBy('id')->get());

    expect($logs)->toHaveCount(2)
        ->and($logs->pluck('payload.tool')->all())->toBe(['crm.get_account', 'crm.create_contact'])
        ->and($logs->every(fn (AuditLog $log) => $log->tenant_id === $this->tenant->id
            && $log->actor_type === ActorType::User
            && $log->actor_id === $user->id
            && $log->result === AuditResult::Ok))->toBeTrue()
        ->and($logs[1]->payload['arguments']['name'])->toBe('Ana Cossa')
        ->and($logs[1]->payload['result']['contact']['id'])->toBe('CNT-0006')
        ->and($logs[0]->payload)->toHaveKey('duration_ms');
});

it('records a tool error as an audited error result, not an exception', function () {
    $result = asTenant($this->tenant, fn () => $this->gateway->call('crm.get_account', ['account_id' => 'ACC-9999']));

    expect($result->ok)->toBeFalse()
        ->and($result->error())->toBe('Cliente ACC-9999 não existe.');

    $log = asTenant($this->tenant, fn () => AuditLog::query()->sole());

    expect($log->result)->toBe(AuditResult::Error)
        ->and($log->actor_type)->toBe(ActorType::System)
        ->and($log->payload['error'])->toBe('Cliente ACC-9999 não existe.');
});

it('throws and audits when the ERP cannot be reached', function () {
    asTenant($this->tenant, fn () => ErpConnection::factory()->create(['base_url' => 'http://127.0.0.1:9/mcp']));

    expect(fn () => asTenant($this->tenant, fn () => $this->gateway->call('erp.health')))->toThrow(ErpException::class);

    $log = asTenant($this->tenant, fn () => AuditLog::query()->sole());

    expect($log->result)->toBe(AuditResult::Error)
        ->and($log->payload['tool'])->toBe('erp.health')
        ->and($log->subject_type)->toBe((new ErpConnection)->getMorphClass());
});

it('uses each tenant\'s own connection', function () {
    $other = Tenant::factory()->create();
    asTenant($this->tenant, fn () => ErpConnection::factory()->local()->create());
    asTenant($other, fn () => ErpConnection::factory()->create(['base_url' => 'http://127.0.0.1:9/mcp']));

    expect(asTenant($this->tenant, fn () => $this->gateway->call('erp.health')->ok))->toBeTrue()
        ->and(fn () => asTenant($other, fn () => $this->gateway->call('erp.health')))->toThrow(ErpException::class);
});

it('never falls back to the .env connection in production', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => asTenant($this->tenant, fn () => $this->gateway->call('erp.health')))->toThrow(ErpNotConfiguredException::class);
});

it('refuses to call the ERP without a tenant', function () {
    expect(fn () => $this->gateway->call('erp.health'))->toThrow(NoTenantException::class);
});

it('tests a connection and caches its capabilities', function () {
    $connection = asTenant($this->tenant, fn () => ErpConnection::factory()->local()->create());

    $connection = asTenant($this->tenant, fn () => $this->gateway->test($connection));

    expect($connection->status)->toBe(ErpConnectionStatus::Ok)
        ->and($connection->capabilities)->toHaveCount(38)
        ->and($connection->last_checked_at)->not->toBeNull()
        ->and($connection->last_error)->toBeNull();
});

it('marks a connection as failing when the test fails', function () {
    $connection = asTenant($this->tenant, fn () => ErpConnection::factory()->create(['base_url' => 'http://127.0.0.1:9/mcp']));

    $connection = asTenant($this->tenant, fn () => $this->gateway->test($connection));

    expect($connection->status)->toBe(ErpConnectionStatus::Error)
        ->and($connection->last_error)->toStartWith('Falha na comunicação com o ERP');
});
