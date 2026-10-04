<?php

use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    [$this->admin, $this->member] = asTenant($this->tenant, fn () => [
        User::factory()->admin()->create(),
        User::factory()->create(),
    ]);
});

it('creates a department with a slug from its name', function () {
    $this->actingAs($this->admin)
        ->post(tenantUrl($this->tenant, 'settings/departments'), ['name' => 'Direcção Financeira'])
        ->assertSessionHasNoErrors();

    expect(asTenant($this->tenant, fn () => Department::query()->where('slug', 'direccao-financeira')->exists()))->toBeTrue();
});

it('lets members see departments but not change them', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/departments'))->assertOk();
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'settings/departments'), ['name' => 'X'])->assertForbidden();
});

it('prevents a department from containing itself', function () {
    [$parent, $child] = asTenant($this->tenant, function () {
        $parent = Department::factory()->create();

        return [$parent, Department::factory()->create(['parent_id' => $parent->id])];
    });

    $this->actingAs($this->admin)
        ->put(tenantUrl($this->tenant, "settings/departments/{$parent->id}"), ['name' => $parent->name, 'parent_id' => $child->id])
        ->assertSessionHasErrors('parent_id');
});

it('detaches people when a department is removed', function () {
    $department = asTenant($this->tenant, fn () => Department::factory()->create());
    asTenant($this->tenant, fn () => $this->member->update(['department_id' => $department->id]));

    $this->actingAs($this->admin)->delete(tenantUrl($this->tenant, "settings/departments/{$department->id}"))->assertSessionHasNoErrors();

    expect(asTenant($this->tenant, fn () => $this->member->fresh()->department_id))->toBeNull();
});
