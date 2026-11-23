<?php

use App\Enums\AgentStatus;
use App\Enums\Role;
use App\Jobs\EmbedKnowledgeItem;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\CompanyFact;
use App\Models\Department;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

// A company set up from its pack (docs/PACOTE-DA-EMPRESA.md). The fixture is a
// fictional company: real client packs stay outside the repository.

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    $this->tenant = Tenant::factory()->create(['slug' => 'acme']);
    $this->owner = asTenant($this->tenant, fn () => User::factory()->owner()->create(['name' => 'Dona Acme']));
    $this->pack = base_path('tests/Fixtures/tenant-pack');
});

/**
 * A copy of the fixture pack that a test may edit.
 */
function editablePack(string $from): string
{
    $to = sys_get_temp_dir().'/tenant-pack-'.uniqid();
    File::copyDirectory($from, $to);

    return $to;
}

it('sets up brand, departments, agents, facts and knowledge from the pack', function () {
    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $this->pack])->assertSuccessful();

    $brand = $this->tenant->fresh()->settings['brand'];
    expect($brand['color'])->toBe('#8F1933')
        ->and($brand['accent_color'])->toBe('#D69E29')
        ->and($brand['font'])->toBe('Montserrat')
        ->and($brand['footer'])->toBe('Acme Industrial · Maputo')
        ->and($brand['logo_path'])->toBe("brand/{$this->tenant->id}-pack-logo.png");
    Storage::disk('local')->assertExists($brand['logo_path']);

    asTenant($this->tenant, function () {
        $general = Department::query()->where('name', 'Direcção-Geral')->firstOrFail();
        $procurement = Department::query()->where('name', 'Procurement')->firstOrFail();

        expect(Department::query()->orderBy('name')->pluck('name')->all())->toBe(['Administração', 'Desenvolvimento de Negócio', 'Direcção-Geral', 'Logística', 'Procurement'])
            ->and($procurement->parent_id)->toBe($general->id)
            ->and($this->owner->fresh()->department_id)->toBe($general->id)
            ->and(Agent::query()->count())->toBe(6)
            ->and(Agent::query()->where('status', AgentStatus::Active)->count())->toBe(6)
            ->and(Agent::query()->where('key', 'procurement')->first())
            ->department_id->toBe($procurement->id)
            ->description->toBe('Sourcing e compras para os pedidos dos clientes.')
            ->reports_to_user_id->toBe($this->owner->id)
            ->and(Agent::query()->where('key', 'triage')->value('suggestions'))->toBe(['O que entrou hoje?'])
            ->and(Agent::query()->where('key', 'hr')->value('department_id'))->toBe(Department::query()->where('name', 'Administração')->value('id'))
            ->and(Agent::query()->where('key', 'hr')->value('reports_to_user_id'))->toBe($this->owner->id);

        expect(CompanyFact::query()->where('status', CompanyFact::PUBLISHED)->count())->toBe(2)
            ->and(CompanyFact::query()->where('status', CompanyFact::PENDING)->value('content'))->toBe('Os preços vêm sempre do ERP.')
            ->and(CompanyFact::query()->where('status', CompanyFact::PUBLISHED)->value('reviewed_by_user_id'))->toBe($this->owner->id);

        $article = KnowledgeItem::query()->where('title', 'Catálogo Acme: protecção auditiva')->firstOrFail();
        $folder = KnowledgeFolder::query()->findOrFail($article->knowledge_folder_id);

        expect($article->status)->toBe('published')
            ->and($article->summary)->toBe('Tampões e abafadores, com o nível de protecção e a norma de cada um.')
            ->and($article->content)->toContain('3M 1100, NRR 37 dB')
            ->and($folder->name)->toBe('Protecção auditiva')
            ->and(KnowledgeFolder::query()->find($folder->parent_id)?->name)->toBe('Produtos e serviços');

        expect(AuditLog::query()->where('action', 'tenant.pack_loaded')->value('payload'))
            ->toMatchArray(['agents_installed' => 6, 'facts' => 3, 'articles_created' => 1]);
    });
});

it('changes nothing when the same pack is loaded again', function () {
    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $this->pack])->assertSuccessful();
    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $this->pack])->assertSuccessful();

    asTenant($this->tenant, function () {
        expect(Department::query()->count())->toBe(5)
            ->and(Agent::query()->count())->toBe(6)
            ->and(CompanyFact::query()->count())->toBe(3)
            ->and(KnowledgeItem::query()->count())->toBe(1)
            ->and(AuditLog::query()->where('action', 'tenant.pack_loaded')->latest('id')->value('payload'))
            ->toMatchArray(['departments' => 0, 'agents_installed' => 0, 'agents_updated' => 6, 'facts' => 0, 'articles_created' => 0, 'articles_updated' => 0]);
    });
});

it('replaces an article whose text changed in the pack and indexes it again', function () {
    $pack = editablePack($this->pack);
    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $pack])->assertSuccessful();

    $file = $pack.'/knowledge/protecao-auditiva.md';
    File::put($file, str_replace('NRR 37 dB', 'NRR 36 dB', File::get($file)));
    Queue::fake();

    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $pack])->assertSuccessful();

    asTenant($this->tenant, fn () => expect(KnowledgeItem::query()->sole()->content)->toContain('NRR 36 dB'));
    Queue::assertPushed(EmbedKnowledgeItem::class, 1);
    File::deleteDirectory($pack);
});

it('gives an agent to the manager of its department', function () {
    asTenant($this->tenant, function () {
        $procurement = Department::query()->create(['name' => 'Procurement', 'slug' => 'procurement']);
        $this->buyer = User::factory()->create(['name' => 'Chefe de Compras', 'role' => Role::Manager, 'department_id' => $procurement->id]);
    });

    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $this->pack, '--draft' => true])->assertSuccessful();

    asTenant($this->tenant, function () {
        expect(Agent::query()->where('key', 'procurement')->first())
            ->reports_to_user_id->toBe($this->buyer->id)
            ->status->toBe(AgentStatus::Draft)
            ->and(Department::query()->where('name', 'Procurement')->count())->toBe(1);
    });
});

it('refuses a pack with a mistake and writes nothing', function () {
    $pack = editablePack($this->pack);
    $company = json_decode(File::get($pack.'/company.json'), true);
    $company['brand']['font'] = 'Comic Sans';
    File::put($pack.'/company.json', json_encode($company));

    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $pack])
        ->expectsOutputToContain('Comic Sans')
        ->assertFailed();

    expect($this->tenant->fresh()->settings['brand'] ?? null)->toBeNull();
    asTenant($this->tenant, fn () => expect(Department::query()->count())->toBe(0)->and(Agent::query()->count())->toBe(0));
    File::deleteDirectory($pack);
});

it('refuses an agent placed in a department the pack does not list', function () {
    $pack = editablePack($this->pack);
    $company = json_decode(File::get($pack.'/company.json'), true);
    $company['agents']['finance']['department'] = 'Finanças';
    File::put($pack.'/company.json', json_encode($company));

    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => $pack])
        ->expectsOutputToContain('Finanças')
        ->assertFailed();
    File::deleteDirectory($pack);
});

it('fails for a tenant that does not exist or a folder without company.json', function () {
    $this->artisan('tenant:load-pack', ['tenant' => 'nada', 'path' => $this->pack])->assertFailed();
    $this->artisan('tenant:load-pack', ['tenant' => 'acme', 'path' => sys_get_temp_dir()])->expectsOutputToContain('company.json')->assertFailed();
});
