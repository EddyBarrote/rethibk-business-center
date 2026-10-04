<?php

use App\Enums\ActorType;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\User;

beforeEach(fn () => $this->tenant = Tenant::factory()->create());

it('is append-only', function () {
    $log = asTenant($this->tenant, fn () => AuditLog::factory()->create());
    $action = $log->action;

    expect(fn () => asTenant($this->tenant, fn () => $log->update(['action' => 'changed'])))->toThrow(LogicException::class, 'append-only')
        ->and(fn () => asTenant($this->tenant, fn () => $log->delete()))->toThrow(LogicException::class, 'append-only')
        ->and(asTenant($this->tenant, fn () => $log->fresh()->action))->toBe($action);
});

it('records who did what', function () {
    $user = asTenant($this->tenant, fn () => User::factory()->create());

    $log = asTenant($this->tenant, fn () => AuditLog::record($user, 'test.action', ['a' => 1]));

    expect($log->actor_type)->toBe(ActorType::User)
        ->and($log->actor_id)->toBe($user->id)
        ->and($log->tenant_id)->toBe($this->tenant->id)
        ->and($log->payload)->toBe(['a' => 1]);
});

it('has no updated_at column', function () {
    expect(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse();
});
