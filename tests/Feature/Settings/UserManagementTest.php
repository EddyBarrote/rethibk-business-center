<?php

use App\Enums\Role;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->admin, $this->member] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(),
        User::factory()->admin()->create(),
        User::factory()->create(),
    ]);
});

function userPayload(array $overrides = []): array
{
    return [
        'name' => 'Beatriz Langa',
        'email' => 'beatriz@micomoc.test',
        'role' => Role::Manager->value,
        'department_id' => null,
        'is_active' => true,
        'password' => 'password-segura-123',
        ...$overrides,
    ];
}

it('lets admins create users in their tenant', function () {
    $department = asTenant($this->tenant, fn () => Department::factory()->create());

    $this->actingAs($this->admin)
        ->post(tenantUrl($this->tenant, 'settings/users'), userPayload(['department_id' => $department->id]))
        ->assertRedirect(route('settings.users.index'));

    $created = asTenant($this->tenant, fn () => User::query()->where('email', 'beatriz@micomoc.test')->first());

    expect($created)->not->toBeNull()
        ->and($created->tenant_id)->toBe($this->tenant->id)
        ->and($created->role)->toBe(Role::Manager)
        ->and($created->department_id)->toBe($department->id);
});

it('forbids members from managing users', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/users'))->assertForbidden();
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'settings/users'), userPayload())->assertForbidden();
});

it('only lets owners create owners', function () {
    $this->actingAs($this->admin)
        ->post(tenantUrl($this->tenant, 'settings/users'), userPayload(['role' => Role::Owner->value]))
        ->assertSessionHasErrors('role');

    $this->actingAs($this->owner)
        ->post(tenantUrl($this->tenant, 'settings/users'), userPayload(['role' => Role::Owner->value]))
        ->assertSessionHasNoErrors();
});

it('stops admins from editing owners', function () {
    $this->actingAs($this->admin)->get(tenantUrl($this->tenant, "settings/users/{$this->owner->id}/edit"))->assertForbidden();
});

it('stops users from changing their own role or deactivating themselves', function () {
    $this->actingAs($this->admin)
        ->put(tenantUrl($this->tenant, "settings/users/{$this->admin->id}"), userPayload([
            'email' => $this->admin->email,
            'role' => Role::Member->value,
            'is_active' => false,
            'password' => null,
        ]))
        ->assertSessionHasErrors(['role', 'is_active']);
});

it('keeps emails unique per tenant only', function () {
    $other = Tenant::factory()->create();
    asTenant($other, fn () => User::factory()->create(['email' => 'beatriz@micomoc.test']));

    $this->actingAs($this->admin)
        ->post(tenantUrl($this->tenant, 'settings/users'), userPayload())
        ->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->post(tenantUrl($this->tenant, 'settings/users'), userPayload(['name' => 'Outra']))
        ->assertSessionHasErrors('email');
});

it('keeps the password when updating without one', function () {
    $hash = $this->member->password;

    $this->actingAs($this->admin)
        ->put(tenantUrl($this->tenant, "settings/users/{$this->member->id}"), userPayload([
            'email' => $this->member->email,
            'role' => Role::Member->value,
            'password' => '',
        ]))
        ->assertRedirect(route('settings.users.index'));

    expect(asTenant($this->tenant, fn () => $this->member->fresh()->password))->toBe($hash);
});
