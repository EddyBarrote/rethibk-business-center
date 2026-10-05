<?php

use App\Enums\Permission;
use App\Models\AccessRole;
use App\Models\Agent;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// The access matrix and access to agents (docs/DECISOES.md, realinhamento L8, L9).

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    [$this->owner, $this->boss, $this->clerk, $this->agent] = asTenant($this->tenant, function () {
        $finance = Department::factory()->create();
        $boss = User::factory()->create(['role' => 'manager', 'department_id' => $finance->id]);

        return [
            User::factory()->owner()->create(),
            $boss,
            User::factory()->create(['role' => 'member', 'department_id' => $finance->id]),
            Agent::factory()->create(['key' => 'finance', 'department_id' => $finance->id, 'reports_to_user_id' => $boss->id]),
        ];
    });
});

it('shows the matrix to those who administer the company and keeps the CEO role administering', function () {
    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant, 'settings/roles'))->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/roles'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Roles')
            ->where('roles', fn ($roles) => collect($roles)->pluck('key')->all() === ['ceo', 'admin', 'chefia', 'funcionario']));

    $ceo = asTenant($this->tenant, fn () => AccessRole::query()->where('key', 'ceo')->sole());
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/roles/{$ceo->id}"), ['name' => 'CEO', 'permissions' => [Permission::ManageWork->value]])
        ->assertSessionHasNoErrors();

    expect($ceo->fresh()->permissions)->toContain(Permission::ManageCompany->value, Permission::ManagePeople->value, Permission::ManageWork->value)
        ->not->toContain(Permission::ManageAgents->value);
});

it('gives a person a custom role and exceptions of their own', function () {
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/roles'), [
        'name' => 'Director', 'permissions' => [Permission::ManageWork->value, Permission::RequestConversationReports->value],
    ])->assertRedirect();

    $director = asTenant($this->tenant, fn () => AccessRole::query()->where('name', 'Director')->sole());

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/users/{$this->clerk->id}"), [
        'name' => $this->clerk->name, 'email' => $this->clerk->email, 'access_role_id' => $director->id, 'is_active' => true,
        'permission_overrides' => [Permission::ManageWork->value => false, Permission::TalkToAllAgents->value => true],
    ])->assertRedirect();

    asTenant($this->tenant, function () {
        $clerk = $this->clerk->fresh();

        expect($clerk->role->value)->toBe('manager')
            ->and($clerk->hasPermission(Permission::RequestConversationReports))->toBeTrue()
            ->and($clerk->hasPermission(Permission::ManageWork))->toBeFalse()
            ->and($clerk->can('requestWork', $this->agent))->toBeTrue();
    });
});

it('lets nobody talk to an agent until given access, and the level decides what they may ask', function () {
    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"))->assertForbidden();
    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('sidebar_agents', []));

    // The chefia of the agent's department grants access; a person outside it cannot.
    $this->actingAs($this->boss)->put(tenantUrl($this->tenant, "agents/{$this->agent->id}/assignees"), ['access' => [['user_id' => $this->clerk->id, 'level' => 'chat']]])->assertRedirect();

    $this->actingAs($this->clerk)->get(tenantUrl($this->tenant, "agents/{$this->agent->id}/chat"))->assertOk();
    $chat = asTenant($this->tenant, fn () => app(TaskThread::class)->conversation($this->clerk, $this->agent));

    $this->actingAs($this->clerk)->post(tenantUrl($this->tenant, "tasks/{$chat->id}/messages"), ['body' => 'Olá', 'mode' => 'message'])->assertRedirect();
    $this->actingAs($this->clerk)->post(tenantUrl($this->tenant, "tasks/{$chat->id}/messages"), ['body' => 'Faz já', 'mode' => 'action'])->assertForbidden();
});

it('keeps a conversation to its person, unless the role reads all conversations', function () {
    $chat = asTenant($this->tenant, function () {
        $this->agent->assignees()->attach($this->clerk->id, ['role' => 'chat']);

        return app(TaskThread::class)->conversation($this->clerk, $this->agent);
    });

    // The person the agent answers to does not read the clerk's conversation.
    $this->actingAs($this->boss)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertForbidden();
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "tasks/{$chat->id}"))->assertOk();
});
