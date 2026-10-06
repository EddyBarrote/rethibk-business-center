<?php

use App\Enums\Permission;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\ErpConnection;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// Definições: every setting in one place, grouped (docs/DECISOES.md, "Definições num só lugar").

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->member] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(),
        User::factory()->create(['role' => 'member', 'password' => Hash::make('antiga-123')]),
    ]);
});

it('opens Definições for everyone, since everyone has their own account there', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Index'));
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/appearance'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Appearance'));
});

it('sends the old addresses to their place in Definições', function (string $from, string $to) {
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, $from))->assertRedirect($to);
})->with([
    'caixas de email' => ['mailboxes', '/settings/mailboxes'],
    'capacidades' => ['capabilities', '/settings/capabilities'],
    'skills' => ['skills', '/settings/skills'],
    'nova skill' => ['skills/create', '/settings/skills/create'],
    'editar skill' => ['skills/7/edit', '/settings/skills/7/edit'],
    'ERP' => ['settings/erp', '/settings/integrations/erp'],
]);

it('shows the ERP in Integrações only to those who administer the company', function () {
    asTenant($this->tenant, fn () => ErpConnection::factory()->create());
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/integrations'))->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/integrations'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Integrations')
            ->where('can_erp', true)->where('can_connectors', true)
            ->where('erp.name', 'Rethink ERP')->where('erp.status', 'untested')
            ->missing('erp.credentials'));

    // Managing the catalogue opens the connectors, not the ERP and its credentials.
    $this->member->forceFill(['permission_overrides' => [Permission::ManageCatalog->value => true]])->save();
    $this->actingAs($this->member->fresh())->get(tenantUrl($this->tenant, 'settings/integrations'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('can_erp', false)->where('can_connectors', true)->where('erp', null));
});

it('lets each person change their own name and, with the current one, their password', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/profile'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Profile')->where('profile.email', $this->member->email)->missing('profile.password'));

    $this->actingAs($this->member)->put(tenantUrl($this->tenant, 'settings/profile'), ['name' => 'Ana Sitoe', 'email' => 'outro@example.test'])->assertSessionHas('success');
    expect($this->member->fresh())->name->toBe('Ana Sitoe')->email->not->toBe('outro@example.test');

    $this->actingAs($this->member)->put(tenantUrl($this->tenant, 'settings/password'), [
        'current_password' => 'errada', 'password' => 'nova-segura-1', 'password_confirmation' => 'nova-segura-1',
    ])->assertSessionHasErrors('current_password');
    $this->actingAs($this->member)->put(tenantUrl($this->tenant, 'settings/password'), [
        'current_password' => 'antiga-123', 'password' => 'curta', 'password_confirmation' => 'curta',
    ])->assertSessionHasErrors('password');

    $this->actingAs($this->member)->put(tenantUrl($this->tenant, 'settings/password'), [
        'current_password' => 'antiga-123', 'password' => 'nova-segura-1', 'password_confirmation' => 'nova-segura-1',
    ])->assertSessionHas('success');

    expect(Hash::check('nova-segura-1', $this->member->fresh()->password))->toBeTrue()
        ->and(asTenant($this->tenant, fn () => AuditLog::query()->where('action', 'user.password_changed')->count()))->toBe(1);
});

it('shows this month\'s AI spend against the caps, per agent, to those who may see costs', function () {
    $this->tenant->update(['settings' => ['ai_budget' => ['tenant_monthly_usd' => 100, 'agent_monthly_usd' => 20]]]);
    [$finance, $triage] = asTenant($this->tenant, function () {
        [$finance, $triage] = [Agent::factory()->create(['name' => 'Agente de Finanças']), Agent::factory()->create(['name' => 'Agente de Triagem'])];
        AgentRun::factory()->for($finance)->count(2)->create(['cost_usd' => 3.5]);
        AgentRun::factory()->for($triage)->create(['cost_usd' => 1.25]);
        AgentRun::factory()->for($triage)->create(['cost_usd' => 9, 'created_at' => now()->subMonthsNoOverflow(2)]);

        return [$finance, $triage];
    });

    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'settings/usage'))->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'settings/usage'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/Usage')
            ->where('spent', 8.25)->where('cap', 100)->where('agent_cap', 20)
            ->has('agents', 2)
            ->where('agents.0.id', $finance->id)->where('agents.0.runs', 2)->where('agents.0.spent', 7)->where('agents.0.cap', 20)
            ->where('agents.1.id', $triage->id)
            ->has('months', 6)
            ->where('months.5.spent', 8.25)
            ->where('months.3.spent', 9));
});
