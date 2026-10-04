<?php

/*
 * Mandatory tenant isolation test (section 4.3 of docs/SPEC.md). It blocks the
 * merge in CI.
 *
 * Models are discovered from app/Models, so every new tenant model is covered
 * as soon as it exists: it only needs the BelongsToTenant trait and a factory.
 */

use App\Ai\Agents\ToolResolver;
use App\Ai\Capabilities\CapabilityContext;
use App\Concerns\BelongsToTenant;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Capability;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Exceptions\NoTenantException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Dataset of tenant models, keyed by short name. Each row is wrapped in an
 * array because Pest would otherwise try to resolve a bare class-string.
 *
 * @return array<string, array{class-string<Model>}>
 */
function tenantModels(): array
{
    $models = [];

    foreach (glob(dirname(__DIR__, 3).'/app/Models/*.php') as $file) {
        $class = 'App\\Models\\'.basename($file, '.php');

        if (in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $models[class_basename($class)] = [$class];
        }
    }

    return $models;
}

it('covers every table that has a tenant_id column', function () {
    $scopedTables = array_map(fn (array $row) => (new $row[0])->getTable(), array_values(tenantModels()));

    $tablesWithTenantId = collect(Schema::getTableListing(schemaQualified: false))
        ->filter(fn (string $table) => Schema::hasColumn($table, 'tenant_id'))
        ->values()
        ->all();

    expect($tablesWithTenantId)->not->toBeEmpty();

    foreach ($tablesWithTenantId as $table) {
        expect($scopedTables)->toContain($table);
    }
});

describe('via Eloquent', function () {
    it('never shows tenant B records to tenant A', function (string $model) {
        [$a, $b] = Tenant::factory()->count(2)->create();

        $ofA = asTenant($a, fn () => $model::factory()->count(2)->create());
        $ofB = asTenant($b, fn () => $model::factory()->count(3)->create());

        asTenant($a, function () use ($model, $ofA, $ofB) {
            expect($model::query()->pluck('id')->sort()->values()->all())
                ->toBe($ofA->pluck('id')->sort()->values()->all());

            foreach ($ofB as $record) {
                expect($model::query()->find($record->id))->toBeNull();
            }
        });

        // Rows really exist for both tenants; only the scope hides them.
        expect(DB::table((new $model)->getTable())->count())->toBeGreaterThanOrEqual(5);
    })->with(tenantModels());

    it('matches nothing when no tenant is set', function (string $model) {
        $tenant = Tenant::factory()->create();
        asTenant($tenant, fn () => $model::factory()->create());

        expect($model::query()->count())->toBe(0);
    })->with(tenantModels());

    it('stamps new records with the current tenant', function (string $model) {
        $tenant = Tenant::factory()->create();

        $record = asTenant($tenant, fn () => $model::factory()->create(['tenant_id' => null]));

        expect($record->tenant_id)->toBe($tenant->id);
    })->with(tenantModels());

    it('refuses to create records without a tenant', function (string $model) {
        $model::factory()->make(['tenant_id' => null])->save();
    })->with(tenantModels())->throws(NoTenantException::class);

    it('refuses to move a record to another tenant', function (string $model) {
        [$a, $b] = Tenant::factory()->count(2)->create();

        asTenant($a, function () use ($model, $b) {
            $record = $model::factory()->create();
            $record->tenant_id = $b->id;
            $record->save();
        });
    })->with(tenantModels())->throws(LogicException::class);
});

describe('via HTTP', function () {
    beforeEach(function () {
        [$this->a, $this->b] = Tenant::factory()->count(2)->create();

        $this->adminA = asTenant($this->a, fn () => User::factory()->admin()->create(['name' => 'Admin A']));

        [$this->userB, $this->departmentB] = asTenant($this->b, fn () => [
            User::factory()->create(['name' => 'Pessoa de B']),
            Department::factory()->create(['name' => 'Departamento de B']),
        ]);

        asTenant($this->a, fn () => Department::factory()->create(['name' => 'Departamento de A']));
    });

    it('lists only the tenant users', function () {
        $this->actingAs($this->adminA)
            ->get(tenantUrl($this->a, 'settings/users'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Settings/Users/Index')
                ->has('users', 1)
                ->where('users.0.name', 'Admin A'));
    });

    it('lists only the tenant departments', function () {
        $this->actingAs($this->adminA)
            ->get(tenantUrl($this->a, 'settings/departments'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('departments', 1)
                ->where('departments.0.name', 'Departamento de A'));
    });

    it('returns 404 for another tenant user', function () {
        $this->actingAs($this->adminA)->get(tenantUrl($this->a, "settings/users/{$this->userB->id}/edit"))->assertNotFound();
        $this->actingAs($this->adminA)->put(tenantUrl($this->a, "settings/users/{$this->userB->id}"), [
            'name' => 'x', 'email' => 'x@x.test', 'role' => 'member',
        ])->assertNotFound();

        expect(asTenant($this->b, fn () => $this->userB->fresh()->name))->toBe('Pessoa de B');
    });

    it('returns 404 for another tenant department', function () {
        $this->actingAs($this->adminA)->put(tenantUrl($this->a, "settings/departments/{$this->departmentB->id}"), ['name' => 'x'])->assertNotFound();
        $this->actingAs($this->adminA)->delete(tenantUrl($this->a, "settings/departments/{$this->departmentB->id}"))->assertNotFound();

        expect(DB::table('departments')->where('id', $this->departmentB->id)->exists())->toBeTrue();
    });

    it('rejects references to another tenant department', function () {
        $this->actingAs($this->adminA)
            ->post(tenantUrl($this->a, 'settings/users'), [
                'name' => 'Nova',
                'email' => 'nova@a.test',
                'role' => Role::Member->value,
                'department_id' => $this->departmentB->id,
                'password' => 'password-segura-123',
            ])
            ->assertSessionHasErrors('department_id');

        $this->actingAs($this->adminA)
            ->post(tenantUrl($this->a, 'settings/departments'), ['name' => 'Filho', 'parent_id' => $this->departmentB->id])
            ->assertSessionHasErrors('parent_id');
    });

    it('does not authenticate a user on another tenant host', function () {
        $this->actingAs($this->adminA)
            ->get(tenantUrl($this->b, '/'))
            ->assertRedirect(route('login'));
    });

    it('does not sign in with credentials from another tenant', function () {
        $this->post(tenantUrl($this->a, 'login'), ['email' => $this->userB->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    });
});

it('isolates agent tools', function () {
    [$a, $b] = Tenant::factory()->count(2)->create();

    $capabilityB = asTenant($b, fn () => Capability::factory()->create(['key' => 'erp.crm.search_accounts']));

    asTenant($a, function () use ($capabilityB) {
        $agent = Agent::factory()->create();
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        // A pivot row pointing at another tenant's capability must not surface it.
        DB::table('agent_capability')->insert(['tenant_id' => $agent->tenant_id, 'agent_id' => $agent->id, 'capability_id' => $capabilityB->id, 'enabled' => true]);

        $names = collect(app(ToolResolver::class)->for(new CapabilityContext($agent, $run)))->map->name()->all();

        expect($names)->not->toContain('erp_crm_search_accounts');
    });
});
