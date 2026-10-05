<?php

use App\Access\AccessRoles;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Everything a person can do is a permission of the matrix, checked on the
// server (docs/DECISOES.md, "Caixas de email por pessoa", ponto 5).

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();
    $this->member = asTenant($this->tenant, fn () => User::factory()->create(['role' => 'member']));
});

it('groups every permission in an area with a label people read', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->group())->toBeIn(['Trabalho', 'Agentes', 'Conversas', 'Emails', 'Conhecimento', 'Documentos', 'Aprovações', 'Custos de IA', 'Empresa'])
            ->and($permission->label())->not->toBe($permission->value);
    }

    expect(AccessRoles::defaultsFor(Role::Member))->toContain(Permission::ConnectOwnMailboxes->value)
        ->not->toContain(Permission::ReadTriageEmails->value)
        ->and(AccessRoles::defaultsFor(Role::Admin))->toContain(Permission::ReadTriageEmails->value, Permission::ManageMailboxes->value);
});

it('refuses on the server what the matrix does not give, and allows it once given', function (Permission $permission, string $method, string $path) {
    $this->actingAs($this->member)->{$method}(tenantUrl($this->tenant, $path), [])->assertForbidden();

    $this->member->forceFill(['permission_overrides' => [$permission->value => true]])->save();
    $status = $this->actingAs($this->member->fresh())->{$method}(tenantUrl($this->tenant, $path), [])->status();

    expect($status)->not->toBe(403);
})->with([
    'projectos' => [Permission::ManageProjects, 'post', 'projects'],
    'objectivos' => [Permission::ManageProjects, 'post', 'goals'],
    'organigrama' => [Permission::ManageOrg, 'put', 'org'],
    'agentes' => [Permission::ManageAgents, 'get', 'agents/new'],
    'capacidades' => [Permission::ManageCatalog, 'get', 'capabilities'],
    'skills' => [Permission::ManageCatalog, 'get', 'skills'],
    'pessoas' => [Permission::ManagePeople, 'get', 'settings/users'],
    'papéis' => [Permission::ManagePeople, 'get', 'settings/roles'],
    'marca' => [Permission::ManageCompany, 'get', 'settings/brand'],
    'domínios' => [Permission::ManageKnowledge, 'get', 'knowledge/domains'],
]);

it('takes away what every role starts with when an exception denies it', function () {
    $this->member->forceFill(['permission_overrides' => [Permission::WriteKnowledge->value => false, Permission::CreateDocuments->value => false]])->save();

    $this->actingAs($this->member->fresh())->get(tenantUrl($this->tenant, 'knowledge/new'))->assertForbidden();
    $this->actingAs($this->member->fresh())->post(tenantUrl($this->tenant, 'documents'), ['title' => 'X'])->assertForbidden();
});

it('shows AI costs only to those the matrix lets see them', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'painel'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_view_costs', false)->where('metrics.month_spend_usd', null));

    $owner = asTenant($this->tenant, fn () => User::factory()->owner()->create());
    $this->actingAs($owner)->get(tenantUrl($this->tenant, 'painel'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_view_costs', true)->whereType('metrics.month_spend_usd', 'double|integer'));
});
