<?php

use App\Ai\Agents\AgentDrafter;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Connector;
use App\Models\PlatformAdmin;
use App\Models\PlatformConnector;
use App\Models\PlatformSkill;
use App\Models\Skill;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Ai\Image;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->member] = asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();

        return [User::factory()->owner()->create(), User::factory()->create()];
    });
});

function agentPayload(array $overrides = []): array
{
    return [
        'key' => 'comercial',
        'name' => 'Amélia',
        'title' => 'Gestora de Propostas',
        'description' => 'Prepara propostas.',
        'personality' => 'Directa e simpática.',
        'instructions' => 'Faz propostas.',
        'status' => 'active',
        'autonomy_level' => 1,
        'capabilities' => [],
        'skills' => [],
        ...$overrides,
    ];
}

it('lets owners and admins create and edit agents, with skills, but not members', function () {
    $skill = asTenant($this->tenant, fn () => Skill::factory()->create());
    $send = asTenant($this->tenant, fn () => Capability::query()->where('key', 'comms.send_email')->value('id'));

    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'agents/new'))->assertForbidden();
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, 'agents'), agentPayload())->assertForbidden();

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'agents/new'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Agents/Form')->where('agent', null)->has('skills', 1));

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'agents'), agentPayload(['capabilities' => [$send], 'skills' => [$skill->id]]))->assertRedirect();

    asTenant($this->tenant, function () use ($skill) {
        $agent = Agent::query()->where('key', 'comercial')->sole();
        expect($agent->created_by_user_id)->toBe($this->owner->id)
            ->and($agent->capabilities()->pluck('key')->all())->toBe(['comms.send_email'])
            ->and($agent->skills()->pluck('skills.id')->all())->toBe([$skill->id])
            ->and(AuditLog::query()->where('action', 'agent.created')->exists())->toBeTrue();
    });

    $agent = asTenant($this->tenant, fn () => Agent::query()->where('key', 'comercial')->sole());
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "agents/{$agent->id}"), agentPayload(['name' => 'Amélia Sitoe', 'skills' => []]))->assertSessionHas('success');
    expect(asTenant($this->tenant, fn () => [$agent->fresh()->name, $agent->skills()->count()]))->toBe(['Amélia Sitoe', 0]);

    $this->actingAs($this->member)->get(tenantUrl($this->tenant, "agents/{$agent->id}/edit"))->assertForbidden();
});

it('shows draft agents only to the people who can edit them', function () {
    asTenant($this->tenant, fn () => Agent::factory()->create(['name' => 'Rascunho', 'status' => AgentStatus::Draft]));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'agents'))->assertInertia(fn (Assert $page) => $page->has('agents', 1)->where('can.create', true));
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'agents'))->assertInertia(fn (Assert $page) => $page->has('agents', 0)->where('can.create', false));
});

it('drafts a colleague from a description, keeping only capabilities and skills that exist', function () {
    $skill = asTenant($this->tenant, fn () => Skill::factory()->create(['key' => 'propostas']));
    AgentDrafter::fake([[
        'name' => 'Amélia',
        'key' => 'Comercial',
        'title' => 'Gestora de Propostas',
        'description' => 'Prepara propostas.',
        'personality' => 'Directa.',
        'instructions' => '# Propostas',
        'autonomy_level' => 2,
        'capabilities' => ['comms.send_email', 'nao.existe'],
        'skills' => ['propostas', 'inventada'],
        'suggested_skills' => [['name' => 'Tabela de preços', 'description' => 'Ao calcular preços.']],
    ]]);

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'agents/new/draft'), ['brief' => 'Alguém que prepare propostas comerciais a partir dos emails.'])
        ->assertRedirect(tenantUrl($this->tenant, 'agents/new'));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'agents/new'))->assertInertia(fn (Assert $page) => $page
        ->where('draft.name', 'Amélia')
        ->where('draft.key', 'comercial')
        ->where('draft.status', 'draft')
        ->where('draft.autonomy_level', 2)
        ->where('draft.skills', [$skill->id])
        ->has('draft.capabilities', 1)
        ->where('draft.suggested_skills.0.name', 'Tabela de preços'));

    AgentDrafter::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'propostas comerciais'));
});

it('uploads, generates and removes an agent photo, served only inside the tenant', function () {
    $agent = asTenant($this->tenant, fn () => Agent::factory()->create(['name' => 'Tomás', 'title' => 'Apoio ao cliente']));

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "agents/{$agent->id}/avatar"), ['avatar' => UploadedFile::fake()->image('foto.png', 200, 200)])->assertSessionHas('success');
    $path = $agent->fresh()->avatar_path;
    Storage::disk('local')->assertExists($path);

    $this->actingAs($this->member)->get(tenantUrl($this->tenant, "agents/{$agent->id}/avatar"))->assertOk();
    $other = Tenant::factory()->create();
    $stranger = asTenant($other, fn () => User::factory()->owner()->create());
    $this->actingAs($stranger)->get(tenantUrl($other, "agents/{$agent->id}/avatar"))->assertNotFound();

    Image::fake([base64_encode('fake-png')]);
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "agents/{$agent->id}/avatar/generate"))->assertSessionHas('success');
    Image::assertGenerated(fn ($prompt) => str_contains($prompt->prompt, 'Tomás') && str_contains($prompt->prompt, 'Apoio ao cliente'));
    Storage::disk('local')->assertMissing($path);

    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "agents/{$agent->id}/avatar"))->assertSessionHas('success');
    expect($agent->fresh()->avatar_path)->toBeNull();
});

it('lets admins switch capabilities off and set the risk of their own connectors only', function () {
    Http::fake(['api.example.test/*' => Http::response(['ok' => true])]);

    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'capabilities'))->assertForbidden();

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'connectors'), [
        'key' => 'leads', 'name' => 'Registar lead', 'description' => 'Regista um lead no CRM da empresa.', 'kind' => 'http',
        'url' => 'https://api.example.test/leads', 'http_method' => 'POST', 'secret' => 'tok-1', 'is_mutating' => true, 'is_active' => true,
        'input_schema' => '{"type":"object","properties":{"name":{"type":"string"}},"required":["name"]}',
    ])->assertSessionHas('success');

    [$own, $local] = asTenant($this->tenant, fn () => [Capability::query()->where('key', 'conn.leads')->sole(), Capability::query()->where('key', 'comms.send_email')->sole()]);
    expect($own->risk)->toBe(AutonomyLevel::ExecuteAndReport)
        ->and(asTenant($this->tenant, fn () => Connector::query()->sole()->secret))->toBe('tok-1');

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'capabilities'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Capabilities/Index')
        ->where('connectors.0.has_secret', true)
        ->missing('connectors.0.secret'));

    $this->actingAs($this->owner)->patch(tenantUrl($this->tenant, "capabilities/{$own->id}"), ['risk' => AutonomyLevel::ExecuteWithinLimits->value])->assertSessionHas('success');
    $this->actingAs($this->owner)->patch(tenantUrl($this->tenant, "capabilities/{$local->id}"), ['risk' => AutonomyLevel::Observe->value])->assertForbidden();
    $this->actingAs($this->owner)->patch(tenantUrl($this->tenant, "capabilities/{$local->id}"), ['is_enabled' => false])->assertSessionHas('success');

    expect($own->fresh()->risk)->toBe(AutonomyLevel::ExecuteWithinLimits)
        ->and($local->fresh()->is_enabled)->toBeFalse();

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'connectors'), [
        'key' => 'mau', 'name' => 'X', 'description' => 'X', 'kind' => 'http', 'url' => 'https://api.example.test/x', 'http_method' => 'GET', 'input_schema' => '[1,2]',
    ])->assertSessionHasErrors('input_schema');
});

it('lets a company write skills with files and activate global ones', function () {
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, 'skills'))->assertForbidden();

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'skills'), [
        'key' => 'propostas', 'name' => 'Propostas', 'description' => 'Ao escrever propostas.', 'instructions' => '# Como', 'is_enabled' => true,
    ])->assertRedirect();
    $skill = asTenant($this->tenant, fn () => Skill::query()->sole());

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "skills/{$skill->id}/files"), [
        'file' => UploadedFile::fake()->createWithContent('precos.csv', "produto,preco\nA,100"),
    ])->assertSessionHas('success');
    expect(asTenant($this->tenant, fn () => $skill->files()->sole()->content))->toContain('produto,preco');

    $global = PlatformSkill::factory()->create(['key' => 'tom', 'name' => 'Tom de voz']);
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "skills/global/{$global->id}"), ['activated' => true])->assertSessionHas('success');

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, 'skills'))->assertInertia(fn (Assert $page) => $page
        ->component('Skills/Index')
        ->has('skills', 1)
        ->where('globalSkills.0.activated', true));

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "skills/global/{$global->id}"), ['activated' => false]);
    expect(asTenant($this->tenant, fn () => Skill::query()->where('platform_skill_id', $global->id)->sole()->is_enabled))->toBeFalse();
});

it('lets the super admin offer global skills and connectors', function () {
    Http::fake();
    $admin = PlatformAdmin::factory()->create();

    $this->actingAs($admin, 'admin')->post(adminUrl('skills'), [
        'key' => 'tom-de-voz', 'name' => 'Tom de voz', 'description' => 'Ao escrever para fora.', 'instructions' => 'Cordial.', 'is_active' => true,
    ])->assertRedirect();
    expect(PlatformSkill::query()->sole()->key)->toBe('tom-de-voz');

    $this->actingAs($admin, 'admin')->post(adminUrl('connectors'), [
        'key' => 'cambio', 'name' => 'Câmbio', 'description' => 'Taxa do dia.', 'kind' => 'http', 'url' => 'http://10.0.0.5/cambio', 'http_method' => 'GET', 'is_active' => true,
    ])->assertSessionHas('success');
    expect(PlatformConnector::query()->sole()->tools)->toHaveCount(1);

    $this->actingAs($admin, 'admin')->get(adminUrl('connectors'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Connectors/Index')->has('connectors', 1));
    $this->actingAs($admin, 'admin')->get(adminUrl('skills'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Skills/Index')->has('skills', 1));
});
