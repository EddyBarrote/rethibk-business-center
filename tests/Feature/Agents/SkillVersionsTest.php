<?php

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Skills\SkillVersions;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PlatformAdmin;
use App\Models\PlatformSkill;
use App\Models\PlatformSkillFile;
use App\Models\PlatformSkillVersion;
use App\Models\Skill;
use App\Models\SkillFile;
use App\Models\SkillVersion;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

// Skill versions (docs/DECISOES.md, "Latência, taxa de erro e versões das
// skills"): every change to what agents read in a skill keeps an immutable,
// numbered copy with its author; restoring writes an old one back as a new
// version, and nothing is ever removed from the history.

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();
    [$this->owner, $this->member] = asTenant($this->tenant, fn () => [
        User::factory()->owner()->create(['name' => 'Edy Barrote']),
        User::factory()->create(['role' => 'member']),
    ]);
});

function skillPayload(array $overrides = []): array
{
    return ['key' => 'propostas', 'name' => 'Propostas', 'description' => 'Ao escrever propostas.', 'instructions' => "# Como\n\n1. Problema do cliente.\n2. Preço no fim.", 'is_enabled' => true, ...$overrides];
}

it('keeps a version each time the content changes, with its author, and none when nothing changed', function () {
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/skills'), skillPayload())->assertRedirect();
    $skill = asTenant($this->tenant, fn () => Skill::query()->sole());

    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/skills/{$skill->id}"), skillPayload(['instructions' => "# Como\n\n1. Problema do cliente.\n2. Preço no fim, válido 30 dias."]))
        ->assertSessionHas('success', 'Skill guardada (versão 2).');
    // Switching it off is not a change of content.
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/skills/{$skill->id}"), skillPayload(['instructions' => "# Como\n\n1. Problema do cliente.\n2. Preço no fim, válido 30 dias.", 'is_enabled' => false]))
        ->assertSessionHas('success', 'Skill guardada.');

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/skills/{$skill->id}/files"), [
        'file' => UploadedFile::fake()->createWithContent('precos.csv', "produto,preco\nA,100"),
    ])->assertSessionHas('success');
    $file = asTenant($this->tenant, fn () => $skill->files()->sole());
    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "settings/skills/{$skill->id}/files/{$file->id}"))->assertSessionHas('success');

    asTenant($this->tenant, function () use ($skill, $file) {
        $versions = $skill->versions()->get();

        expect($versions->pluck('number')->all())->toBe([4, 3, 2, 1])
            ->and($versions->pluck('created_by_user_id')->unique()->all())->toBe([$this->owner->id])
            ->and($versions->firstWhere('number', 1)->instructions)->toBe("# Como\n\n1. Problema do cliente.\n2. Preço no fim.")
            ->and($versions->firstWhere('number', 2)->instructions)->toContain('válido 30 dias')
            ->and(collect($versions->firstWhere('number', 3)->files)->pluck('filename')->all())->toBe(['precos.csv'])
            ->and($versions->firstWhere('number', 3)->files[0]['content'])->toContain('produto,preco')
            ->and($versions->firstWhere('number', 4)->files)->toBe([])
            ->and(AuditLog::query()->where('action', 'skill.updated')->latest('id')->first()->payload)->toMatchArray(['version' => null]);

        // The removed file stays on disk for version 3.
        Storage::disk('local')->assertExists($file->path);
    });
});

it('shows the history, a version and its line diff against the current one, only to who manages the catalogue', function () {
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/skills'), skillPayload())->assertRedirect();
    $skill = asTenant($this->tenant, fn () => Skill::query()->sole());
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/skills/{$skill->id}"), skillPayload([
        'description' => 'Ao escrever propostas comerciais.',
        'instructions' => "# Como\n\n1. Problema do cliente.\n2. Solução.\n3. Preço no fim.",
    ]));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/skills/{$skill->id}/edit"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Skills/Form')
            ->has('versions', 2)
            ->where('versions.0', fn ($row) => $row['number'] === 2 && $row['is_current'] === true && $row['author'] === 'Edy Barrote' && $row['restored_from'] === null)
            ->where('versions.1.is_current', false));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/1"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Skills/Version')
            ->where('version.number', 1)
            ->where('version.is_current', false)
            ->where('version.description', 'Ao escrever propostas.')
            ->where('diff.changed', true)
            ->where('diff.description', [
                ['type' => 'removed', 'text' => 'Ao escrever propostas.'],
                ['type' => 'added', 'text' => 'Ao escrever propostas comerciais.'],
            ])
            ->where('diff.instructions', [
                ['type' => 'same', 'text' => '# Como'],
                ['type' => 'same', 'text' => ''],
                ['type' => 'same', 'text' => '1. Problema do cliente.'],
                ['type' => 'removed', 'text' => '2. Preço no fim.'],
                ['type' => 'added', 'text' => '2. Solução.'],
                ['type' => 'added', 'text' => '3. Preço no fim.'],
            ])
            ->where('diff.name', [['type' => 'same', 'text' => 'Propostas']]));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/9"))->assertNotFound();
    $this->actingAs($this->member)->get(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/1"))->assertForbidden();
    $this->actingAs($this->member)->post(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/1/restore"))->assertForbidden();
});

it('restores a version as a new one, files included, audited, without losing any', function () {
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, 'settings/skills'), skillPayload())->assertRedirect();
    $skill = asTenant($this->tenant, fn () => Skill::query()->sole());
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/skills/{$skill->id}/files"), [
        'file' => UploadedFile::fake()->createWithContent('modelo.md', '# Modelo antigo'),
    ]);
    // Same name: the new file replaces the old one in the skill.
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/skills/{$skill->id}/files"), [
        'file' => UploadedFile::fake()->createWithContent('modelo.md', '# Modelo novo'),
    ]);
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/skills/{$skill->id}"), skillPayload(['key' => 'propostas-v2', 'name' => 'Propostas novas', 'instructions' => 'Outra coisa.']));

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/2"))
        ->assertInertia(fn (Assert $page) => $page->where('diff.files', [['filename' => 'modelo.md', 'status' => 'changed', 'size' => strlen('# Modelo novo')]]));

    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/2/restore"))
        ->assertRedirect(tenantUrl($this->tenant, "settings/skills/{$skill->id}/edit"))
        ->assertSessionHas('success', 'Versão 2 reposta: é agora a versão 5.');

    asTenant($this->tenant, function () use ($skill) {
        $skill->refresh();
        $restored = $skill->versions()->first();

        expect($skill->instructions)->toBe("# Como\n\n1. Problema do cliente.\n2. Preço no fim.")
            ->and($skill->name)->toBe('Propostas')
            // The key stays: agents find the skill by it.
            ->and($skill->key)->toBe('propostas-v2')
            ->and($skill->files()->sole()->content)->toBe('# Modelo antigo')
            ->and($restored->number)->toBe(5)
            ->and($restored->restored_from)->toBe(2)
            ->and($restored->created_by_user_id)->toBe($this->owner->id)
            ->and($skill->versions()->pluck('number')->all())->toBe([5, 4, 3, 2, 1])
            ->and(AuditLog::query()->where('action', 'skill.version_restored')->sole()->payload)->toBe(['key' => 'propostas-v2', 'restored_from' => 2, 'version' => 5]);

        Storage::disk('local')->assertExists($skill->files()->sole()->path);
    });

    // Restoring the current content still leaves a mark in the history.
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/5/restore"))->assertSessionHas('success');
    expect(asTenant($this->tenant, fn () => $skill->versions()->first()->only(['number', 'restored_from'])))->toBe(['number' => 6, 'restored_from' => 5]);

    // Deleting the skill takes its history and every stored file with it.
    $this->actingAs($this->owner)->delete(tenantUrl($this->tenant, "settings/skills/{$skill->id}"))->assertRedirect();
    expect(DB::table('skill_versions')->where('skill_id', $skill->id)->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles("skills/{$this->tenant->id}/{$skill->id}"))->toBe([]);
});

it('never changes nor deletes a version', function () {
    $version = asTenant($this->tenant, fn () => SkillVersion::factory()->create());

    asTenant($this->tenant, fn () => $version->update(['instructions' => 'Outra']));
})->throws(LogicException::class, 'Skill versions are immutable.');

it('keeps the version a skill had before its first recorded change, and tells agents which one they loaded', function () {
    [$skill, $agent] = asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $skill = Skill::factory()->create(['key' => 'propostas', 'instructions' => 'Antes.']);
        $agent = Agent::factory()->create();
        $agent->skills()->attach($skill->id);

        return [$skill, $agent];
    });

    // Made by a path that kept no version (a factory here): the first edit keeps what it had.
    $this->actingAs($this->owner)->put(tenantUrl($this->tenant, "settings/skills/{$skill->id}"), skillPayload(['instructions' => 'Depois.']));

    asTenant($this->tenant, function () use ($skill, $agent) {
        expect($skill->versions()->pluck('instructions', 'number')->all())->toBe([2 => 'Depois.', 1 => 'Antes.'])
            ->and($skill->versions()->firstWhere('number', 1)->created_by_user_id)->toBeNull()
            // Agents read the current version, and the run log says which.
            ->and(runCapability($agent, 'skills.load', ['skill' => 'propostas'])->content)->toStartWith('# Skill: Propostas (propostas, versão 2)')
            ->and(runCapability($agent, 'skills.load', ['skill' => 'propostas'])->content)->toContain('Depois.');
    });
});

it('keeps each tenant\'s versions to itself', function () {
    $other = Tenant::factory()->create();
    [$theirs, $theirVersion] = asTenant($other, function () {
        $skill = Skill::factory()->create();
        app(SkillVersions::class)->record($skill, null);

        return [$skill, $skill->versions()->sole()];
    });

    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/skills/{$theirs->id}/versions/1"))->assertNotFound();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/skills/{$theirs->id}/versions/1/restore"))->assertNotFound();

    expect(asTenant($this->tenant, fn () => SkillVersion::query()->find($theirVersion->id)))->toBeNull()
        ->and(asTenant($other, fn () => $theirs->versions()->count()))->toBe(1);
});

it('has no versions for an activated global skill on the company side', function () {
    $global = PlatformSkill::factory()->create();
    $activated = asTenant($this->tenant, fn () => Skill::factory()->global($global)->create());

    expect(asTenant($this->tenant, fn () => app(SkillVersions::class)->record($activated, $this->owner)))->toBeNull();
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/skills/{$activated->id}/versions/1"))->assertNotFound();
    $this->actingAs($this->owner)->post(tenantUrl($this->tenant, "settings/skills/{$activated->id}/versions/1/restore"))->assertNotFound();
});

it('versions global skills for the super admin, who restores them for every organisation', function () {
    $admin = PlatformAdmin::factory()->create(['name' => 'Operador Rethink']);

    $this->actingAs($admin, 'admin')->post(adminUrl('skills'), [
        'key' => 'tom-de-voz', 'name' => 'Tom de voz', 'description' => 'Ao escrever para fora.', 'instructions' => 'Cordial.', 'is_active' => true,
    ])->assertRedirect();
    $skill = PlatformSkill::query()->sole();
    $this->actingAs($admin, 'admin')->post(adminUrl("skills/{$skill->id}/files"), ['file' => UploadedFile::fake()->createWithContent('exemplos.md', 'Bom dia.')]);
    $this->actingAs($admin, 'admin')->put(adminUrl("skills/{$skill->id}"), [
        'key' => 'tom-de-voz', 'name' => 'Tom de voz', 'description' => 'Ao escrever para fora.', 'instructions' => 'Formal.', 'is_active' => true,
    ])->assertSessionHas('success');

    expect($skill->versions()->pluck('number')->all())->toBe([3, 2, 1])
        ->and($skill->versions()->pluck('created_by_admin_id')->unique()->all())->toBe([$admin->id]);

    $this->actingAs($admin, 'admin')->get(adminUrl("skills/{$skill->id}/edit"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Skills/Form')->has('versions', 3)->where('versions.0.author', 'Operador Rethink'));
    $this->actingAs($admin, 'admin')->get(adminUrl("skills/{$skill->id}/versions/1"))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Admin/Skills/Version')
            ->where('diff.instructions', [['type' => 'removed', 'text' => 'Cordial.'], ['type' => 'added', 'text' => 'Formal.']])
            ->where('diff.files', [['filename' => 'exemplos.md', 'status' => 'added', 'size' => 8]]));

    // A company that activated it reads whatever version is current.
    [$agent, $activated] = asTenant($this->tenant, function () use ($skill) {
        app(CapabilityCatalog::class)->syncLocal();
        $activated = Skill::factory()->global($skill)->create();
        $agent = Agent::factory()->create();
        $agent->skills()->attach($activated->id);

        return [$agent, $activated];
    });

    $this->actingAs($admin, 'admin')->post(adminUrl("skills/{$skill->id}/versions/1/restore"))->assertRedirect(adminUrl("skills/{$skill->id}/edit"));

    $skill->refresh();
    expect($skill->instructions)->toBe('Cordial.')
        ->and($skill->files()->count())->toBe(0)
        ->and($skill->versions()->first()->only(['number', 'restored_from', 'created_by_admin_id']))->toBe(['number' => 4, 'restored_from' => 1, 'created_by_admin_id' => $admin->id])
        ->and(asTenant($this->tenant, fn () => runCapability($agent, 'skills.load', ['skill' => $activated->key])->content))->toContain('versão 4')->toContain('Cordial.');

    // A company never reaches the global history through its own pages.
    $this->actingAs($this->owner)->get(tenantUrl($this->tenant, "settings/skills/{$skill->id}/versions/1"))->assertNotFound();
    $this->actingAs($this->owner)->post(adminUrl("skills/{$skill->id}/versions/1/restore"))->assertForbidden();
    expect($skill->versions()->count())->toBe(4);
});

it('gives every existing skill its version 1 when the versions arrive', function () {
    $global = PlatformSkill::factory()->create(['instructions' => 'Global.']);
    PlatformSkillFile::factory()->create(['platform_skill_id' => $global->id, 'filename' => 'exemplos.md', 'content' => 'Exemplo']);
    [$own, $activated, $theirs] = [
        asTenant($this->tenant, function () {
            $skill = Skill::factory()->create(['instructions' => 'Nossa.', 'created_by_user_id' => $this->owner->id]);
            SkillFile::factory()->create(['skill_id' => $skill->id, 'filename' => 'modelo.md', 'path' => 'skills/x/modelo.md', 'content' => 'Modelo']);

            return $skill;
        }),
        asTenant($this->tenant, fn () => Skill::factory()->global($global)->create()),
        asTenant(Tenant::factory()->create(), fn () => Skill::factory()->create(['instructions' => 'Deles.'])),
    ];

    $migration = require database_path('migrations/2026_11_23_100001_skill_versions.php');
    $migration->down();
    $migration->up();

    asTenant($this->tenant, function () use ($own, $activated) {
        $version = $own->versions()->sole();

        expect($version->only(['number', 'instructions', 'created_by_user_id']))->toBe(['number' => 1, 'instructions' => 'Nossa.', 'created_by_user_id' => $this->owner->id])
            ->and($version->files)->toBe([['filename' => 'modelo.md', 'path' => 'skills/x/modelo.md', 'mime' => 'text/markdown', 'size' => 42, 'content' => 'Modelo']])
            ->and($activated->versions()->count())->toBe(0);
    });

    expect(DB::table('skill_versions')->where('skill_id', $theirs->id)->value('tenant_id'))->toBe($theirs->tenant_id)
        ->and(PlatformSkillVersion::query()->where('platform_skill_id', $global->id)->sole()->only(['number', 'instructions']))->toBe(['number' => 1, 'instructions' => 'Global.'])
        ->and(collect(PlatformSkillVersion::query()->where('platform_skill_id', $global->id)->sole()->files)->pluck('filename')->all())->toBe(['exemplos.md']);
});
