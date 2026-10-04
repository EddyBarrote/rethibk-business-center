<?php

use App\Enums\ActorType;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\Skill;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->admin = PlatformAdmin::factory()->create();
    $this->tenant = Tenant::factory()->create(['name' => 'MICOMOC']);
});

it('sends guests to the admin login and keeps tenant users out', function () {
    $this->get(adminUrl('tenants'))->assertRedirect(adminUrl('login'));

    $user = asTenant($this->tenant, fn () => User::factory()->owner()->create());
    $this->actingAs($user, 'web')->get(adminUrl('tenants'))->assertRedirect(adminUrl('login'));
});

it('signs a super admin in and lists tenants', function () {
    $this->post(adminUrl('login'), ['email' => $this->admin->email, 'password' => 'password'])->assertRedirect(adminUrl('tenants'));

    $this->get(adminUrl('tenants'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Admin/Tenants/Index')
        ->where('tenants.0.name', 'MICOMOC'));
});

it('does not let a super admin session into a tenant console', function () {
    // Each request starts on the web guard; only the admin host switches to 'admin'.
    $this->actingAs($this->admin, 'admin');
    auth()->shouldUse('web');

    $this->get(tenantUrl($this->tenant, '/'))->assertRedirect(route('login'));
});

it('creates a tenant with its owner and local skills, and refuses reserved slugs', function () {
    $this->actingAs($this->admin, 'admin')->post(adminUrl('tenants'), [
        'name' => 'Admin Lda', 'slug' => 'admin', 'owner_name' => 'X', 'owner_email' => 'x@x.co.mz', 'owner_password' => 'segredo-123',
    ])->assertSessionHasErrors('slug');

    $this->actingAs($this->admin, 'admin')->post(adminUrl('tenants'), [
        'name' => 'Nova', 'slug' => 'nova', 'owner_name' => 'Ana', 'owner_email' => 'ana@nova.co.mz', 'owner_password' => 'segredo-123',
    ])->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'nova')->sole();

    asTenant($tenant, function () {
        expect(User::query()->sole()->email)->toBe('ana@nova.co.mz')
            ->and(Skill::query()->pluck('key')->all())->toContain('memory.search', 'comms.send_email');
    });
});

it('saves the profile and the AI budget, and audits it', function () {
    $this->actingAs($this->admin, 'admin')->put(adminUrl("tenants/{$this->tenant->id}"), [
        'name' => 'MICOMOC, SA',
        'status' => 'active',
        'budget' => ['tenant_monthly_usd' => '200', 'agent_monthly_usd' => '50', 'run_usd' => ''],
        'email_retention_days' => 180,
        'mail_domain' => 'agentes.micomoc.co.mz',
        'tender_sources' => [['name' => 'UFSA', 'url' => 'https://www.ufsa.gov.mz', 'keywords' => 'manutenção', 'active' => true]],
    ])->assertSessionHas('success');

    $tenant = $this->tenant->fresh();
    expect($tenant->name)->toBe('MICOMOC, SA')
        ->and($tenant->settings['ai_budget'])->toEqual(['tenant_monthly_usd' => 200.0, 'agent_monthly_usd' => 50.0, 'run_usd' => null])
        ->and($tenant->settings['tender_sources'][0]['name'])->toBe('UFSA');

    $log = asTenant($tenant, fn () => AuditLog::query()->where('action', 'tenant.updated')->sole());
    expect($log->actor_type)->toBe(ActorType::PlatformAdmin);
});

it('defines an agent inside the right tenant, with skills from that tenant only', function () {
    $other = Tenant::factory()->create();
    $foreignSkill = asTenant($other, fn () => Skill::factory()->create());
    $skill = asTenant($this->tenant, fn () => Skill::factory()->create(['key' => 'erp.leads.create', 'is_mutating' => true]));

    $payload = [
        'key' => 'triagem', 'name' => 'Triagem', 'status' => 'active', 'autonomy_level' => 2,
        'instructions' => 'Classifica emails.', 'skills' => [$foreignSkill->id],
    ];

    $this->actingAs($this->admin, 'admin')->post(adminUrl("tenants/{$this->tenant->id}/agents"), $payload)->assertSessionHasErrors('skills.0');
    $this->actingAs($this->admin, 'admin')->post(adminUrl("tenants/{$this->tenant->id}/agents"), [...$payload, 'skills' => [$skill->id]])->assertRedirect();

    asTenant($this->tenant, function () use ($skill) {
        $agent = Agent::query()->sole();

        expect($agent->autonomy_level)->toBe(AutonomyLevel::ExecuteWithApproval)
            ->and($agent->created_by_admin_id)->toBe($this->admin->id)
            ->and($agent->skills()->pluck('skills.id')->all())->toBe([$skill->id]);
    });

    expect(asTenant($other, fn () => Agent::query()->count()))->toBe(0);
});

it('answers 404 for an agent of another tenant under this tenant url', function () {
    $other = Tenant::factory()->create();
    $agent = asTenant($other, fn () => Agent::factory()->create());

    $this->actingAs($this->admin, 'admin')->get(adminUrl("tenants/{$this->tenant->id}/agents/{$agent->id}/edit"))->assertNotFound();
});

it('validates routine schedules', function () {
    $agent = asTenant($this->tenant, fn () => Agent::factory()->create());

    $this->actingAs($this->admin, 'admin')->post(adminUrl("tenants/{$this->tenant->id}/agents/{$agent->id}/routines"), ['name' => 'X', 'prompt' => 'Y', 'schedule' => 'todos os dias'])
        ->assertSessionHasErrors('schedule');
    $this->actingAs($this->admin, 'admin')->post(adminUrl("tenants/{$this->tenant->id}/agents/{$agent->id}/routines"), ['name' => 'Briefing', 'prompt' => 'Prepara o briefing.', 'schedule' => '30 6 * * 1-5'])
        ->assertSessionHas('success');

    expect(asTenant($this->tenant, fn () => $agent->routines()->sole()->schedule))->toBe('30 6 * * 1-5');
});
