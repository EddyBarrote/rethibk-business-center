<?php

use App\Ai\Agents\GenericAgent;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Runs\AgentRunner;
use App\Documents\DocumentFormat;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentSpec;
use App\Enums\AutonomyLevel;
use App\Enums\KnowledgeType;
use App\Enums\Role;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\Capability;
use App\Models\Department;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Responses\Data\ToolCall;

// The knowledge base: domains with access by department, folders, articles,
// uploads, and what agents read and write (docs/CONHECIMENTO.md).

beforeEach(function () {
    config(['ai.providers.openai.key' => null, 'ai.providers.gemini.key' => null]);
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->hr = Department::factory()->create(['name' => 'Direcção de RH', 'slug' => 'direccao-de-rh']);
        $this->finance = Department::factory()->create(['name' => 'Direcção Financeira', 'slug' => 'direccao-financeira']);
        $this->owner = User::factory()->owner()->create();
        $this->hrManager = User::factory()->role(Role::Manager)->create(['department_id' => $this->hr->id]);
        $this->financeManager = User::factory()->role(Role::Manager)->create(['department_id' => $this->finance->id]);
        $this->member = User::factory()->role(Role::Member)->create(['department_id' => $this->finance->id]);
        app(KnowledgeAccess::class)->ensureDefaults();
        $this->rh = KnowledgeDomain::query()->where('slug', 'rh')->firstOrFail();
        $this->general = KnowledgeDomain::query()->where('slug', 'geral')->firstOrFail();
    });
});

function inDomain(KnowledgeDomain $domain, string $title, string $content, array $attributes = []): KnowledgeItem
{
    return app(KnowledgeBase::class)->remember(KnowledgeType::Article, $title, $content, null, ['knowledge_domain_id' => $domain->id, ...$attributes]);
}

it('creates the default domains, with HR and Finance open only to their departments', function () {
    asTenant($this->tenant, function () {
        expect(KnowledgeDomain::query()->orderBy('position')->pluck('slug')->all())->toBe(['geral', 'financas', 'rh', 'clientes', 'operacoes'])
            ->and($this->rh->department_ids)->toBe([$this->hr->id])
            ->and(KnowledgeDomain::query()->where('slug', 'financas')->firstOrFail()->department_ids)->toBe([$this->finance->id])
            ->and($this->general->department_ids)->toBeNull();
    });
});

it('shows a restricted domain only to its departments, owners and its agents', function () {
    asTenant($this->tenant, function () {
        inDomain($this->rh, 'Tabela salarial 2026', 'Escalões salariais dos técnicos de obra.');
        inDomain($this->general, 'Horário de funcionamento', 'A empresa abre às 7h30.');
        $kb = app(KnowledgeBase::class);

        expect($kb->search('salarial', 5, $this->hrManager))->toHaveCount(1)
            ->and($kb->search('salarial', 5, $this->owner))->toHaveCount(1)
            ->and($kb->search('salarial', 5, $this->financeManager))->toHaveCount(0)
            ->and($kb->search('horário funcionamento', 5, $this->member))->toHaveCount(1);

        $hrAgent = Agent::factory()->create(['department_id' => $this->hr->id]);
        $financeAgent = Agent::factory()->create(['department_id' => $this->finance->id]);
        app(CapabilityCatalog::class)->syncLocal();

        expect(runCapability($hrAgent, 'memory.search', ['query' => 'salarial'])->content)->toContain('Tabela salarial')
            ->and(runCapability($financeAgent, 'memory.search', ['query' => 'salarial'])->content)->toContain('Nada encontrado')
            ->and(runCapability($financeAgent, 'knowledge.browse', [])->data['domains'])->not->toContain(fn ($d) => $d['name'] === 'Recursos Humanos');

        $browse = collect(runCapability($financeAgent, 'knowledge.browse', [])->data['domains'])->pluck('name');
        expect($browse)->not->toContain('Recursos Humanos')->toContain('Geral');
    });

    $item = asTenant($this->tenant, fn () => KnowledgeItem::query()->where('title', 'Tabela salarial 2026')->firstOrFail());
    $this->actingAs(asTenant($this->tenant, fn () => $this->financeManager), 'web')->get(tenantUrl($this->tenant, "knowledge/{$item->id}"))->assertForbidden();
    $this->actingAs(asTenant($this->tenant, fn () => $this->hrManager), 'web')->get(tenantUrl($this->tenant, "knowledge/{$item->id}"))->assertOk();
});

it('lets a person write an article in a folder, and only its author or a curator edit it', function () {
    [$folder, $member, $owner] = asTenant($this->tenant, fn () => [
        KnowledgeFolder::factory()->create(['knowledge_domain_id' => $this->general->id, 'name' => 'Procedimentos']),
        $this->member,
        $this->owner,
    ]);

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'knowledge'), [
        'type' => 'article',
        'title' => 'Como pedir férias',
        'content' => "# Férias\n\nPedir com 15 dias de antecedência.",
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
        'folder_id' => $folder->id,
    ])->assertRedirect();

    $item = asTenant($this->tenant, fn () => KnowledgeItem::query()->where('title', 'Como pedir férias')->firstOrFail());
    expect($item->knowledge_folder_id)->toBe($folder->id)->and($item->status)->toBe('published')->and($item->created_by_id)->toBe($owner->id);

    $this->actingAs($member, 'web')->put(tenantUrl($this->tenant, "knowledge/{$item->id}"), [
        'type' => 'article', 'title' => 'Mudado', 'content' => 'x', 'domain_id' => $item->knowledge_domain_id,
    ])->assertForbidden();

    $this->actingAs($member, 'web')->get(tenantUrl($this->tenant, 'knowledge?domain=geral&folder='.$folder->id))->assertOk()
        ->assertInertia(fn ($page) => $page->component('Knowledge/Index')->where('items.0.title', 'Como pedir férias')->where('folder.name', 'Procedimentos'));

    // A member cannot file into a domain they cannot open.
    $this->actingAs($member, 'web')->post(tenantUrl($this->tenant, 'knowledge'), [
        'type' => 'article', 'title' => 'Fuga', 'content' => 'x', 'domain_id' => asTenant($this->tenant, fn () => $this->rh->id),
    ])->assertForbidden();
});

it('uploads files, extracts their text for search and serves the original', function () {
    $paths = asTenant($this->tenant, function () {
        $generator = app(DocumentGenerator::class);

        return collect(['docx', 'xlsx', 'pptx', 'pdf'])->mapWithKeys(function (string $format) use ($generator) {
            $document = $generator->generate(DocumentFormat::from($format), new DocumentSpec("Manual {$format}", "## Regras\n\n| Regra | Valor |\n|---|---|\n| Limite de caixa {$format} | 15 000 |\n\nTexto sobre cimento Portland."));

            return [$format => Storage::disk('local')->path($document->path)];
        });
    });
    $owner = asTenant($this->tenant, fn () => $this->owner);

    $files = $paths->map(fn (string $path, string $format) => new UploadedFile($path, "manual.{$format}", null, null, true))->values()->all();

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'knowledge/upload'), [
        'files' => $files,
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
    ])->assertRedirect()->assertSessionHasNoErrors();

    asTenant($this->tenant, function () {
        $items = KnowledgeItem::query()->whereNotNull('path')->get()->keyBy(fn ($i) => pathinfo($i->filename, PATHINFO_EXTENSION));
        expect($items->keys()->sort()->values()->all())->toBe(['docx', 'pdf', 'pptx', 'xlsx']);

        foreach (['docx', 'xlsx', 'pptx', 'pdf'] as $format) {
            expect($items[$format]->content)->toContain("Limite de caixa {$format}")
                ->and($items[$format]->type)->toBe(KnowledgeType::Document);
            Storage::disk('local')->assertExists($items[$format]->path);
        }

        expect(app(KnowledgeBase::class)->search('Portland', 10, $this->member))->toHaveCount(4);
    });

    $pdf = asTenant($this->tenant, fn () => KnowledgeItem::query()->where('filename', 'manual.pdf')->firstOrFail());
    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, "knowledge/{$pdf->id}/file?inline=1"))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, "knowledge/{$pdf->id}"))->assertInertia(fn ($page) => $page->where('item.preview', 'pdf'));
});

it('keeps what a low-autonomy agent writes away from other agents until a person approves it', function () {
    [$item, $writer] = asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $writer = Agent::factory()->level(AutonomyLevel::Suggest)->create(['reports_to_user_id' => $this->owner->id]);
        $result = runCapability($writer, 'knowledge.save', [
            'title' => 'Mozal paga a 60 dias',
            'content' => 'A Mozal paga sempre a 60 dias da factura, nunca antes.',
            'domain' => 'Clientes',
            'folder' => 'Mozal/Pagamentos',
        ]);

        expect($result->ok)->toBeTrue()->and($result->data['status'])->toContain('revisão');

        return [KnowledgeItem::query()->findOrFail($result->data['knowledge_item_id']), $writer];
    });

    asTenant($this->tenant, function () use ($item, $writer) {
        expect($item->status)->toBe('pending_review')
            ->and($item->created_by_type)->toBe($writer->getMorphClass())
            ->and($item->folder->path())->toBe('Mozal / Pagamentos')
            ->and($this->owner->notifications()->count())->toBe(1);

        $reader = Agent::factory()->create();
        expect(runCapability($reader, 'memory.search', ['query' => 'Mozal'])->content)->toContain('Nada encontrado')
            ->and(app(KnowledgeBase::class)->search('Mozal', 5, $this->member))->toHaveCount(1);
    });

    $owner = asTenant($this->tenant, fn () => $this->owner);
    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, 'knowledge?view=review'))
        ->assertInertia(fn ($page) => $page->where('pending', 1)->where('items.0.author.kind', 'agent'));
    $this->actingAs(asTenant($this->tenant, fn () => $this->member), 'web')->post(tenantUrl($this->tenant, "knowledge/{$item->id}/review"), ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, "knowledge/{$item->id}/review"), ['decision' => 'approve'])->assertRedirect();

    asTenant($this->tenant, function () use ($item) {
        expect($item->fresh()->status)->toBe('published')->and($item->fresh()->reviewed_by_user_id)->toBe($this->owner->id);
        expect(runCapability(Agent::factory()->create(), 'memory.search', ['query' => 'Mozal'])->content)->toContain('60 dias');
    });
});

it('publishes at once what an agent at N3 writes, through a real run', function () {
    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->level(AutonomyLevel::ExecuteWithinLimits)->create();
        $agent->capabilities()->attach(Capability::query()->where('key', 'knowledge.save')->value('id'), ['enabled' => true]);

        GenericAgent::fake([
            new ToolCall('1', 'knowledge_save', ['title' => 'Fornecedor de varão', 'content' => 'A Cimentos de Moçambique entrega em 48 h.', 'domain' => 'operações']),
            'Guardado.',
        ]);

        $run = app(AgentRunner::class)->create($agent, 'Guarda o que aprendeste sobre o fornecedor.', TriggerType::Manual);
        app(AgentRunner::class)->run($run);

        $item = KnowledgeItem::query()->where('title', 'Fornecedor de varão')->firstOrFail();
        expect($item->status)->toBe('published')->and($item->domain->slug)->toBe('operacoes');
    });
});

it('refuses to let an agent write into a domain it cannot open', function () {
    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->level(AutonomyLevel::ExecuteAndReport)->create(['department_id' => $this->finance->id]);

        $result = runCapability($agent, 'knowledge.save', ['title' => 'Salários', 'content' => 'x', 'domain' => 'Recursos Humanos']);

        expect($result->ok)->toBeFalse()->and(KnowledgeItem::query()->count())->toBe(0);
    });
});

it('reads a long item by parts and fences external content', function () {
    asTenant($this->tenant, function () {
        $item = inDomain($this->general, 'Caderno de encargos', str_repeat('Cláusula. ', 3000), ['is_external' => true]);
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->create();

        $first = runCapability($agent, 'knowledge.read', ['id' => $item->id]);
        expect($first->content)->toContain('<dados_externos_nao_confiaveis>')->toContain('offset=12000');

        $rh = inDomain($this->rh, 'Processo disciplinar', 'Confidencial.');
        expect(runCapability($agent, 'knowledge.read', ['id' => $rh->id])->ok)->toBeFalse();
    });
});

it('moves the content of a deleted folder to the domain root and never deletes a domain with documents', function () {
    [$parent, $child, $item] = asTenant($this->tenant, function () {
        $parent = KnowledgeFolder::factory()->create(['knowledge_domain_id' => $this->general->id]);
        $child = KnowledgeFolder::factory()->create(['knowledge_domain_id' => $this->general->id, 'parent_id' => $parent->id]);

        return [$parent, $child, inDomain($this->general, 'Dentro', 'texto', ['knowledge_folder_id' => $child->id])];
    });
    $owner = asTenant($this->tenant, fn () => $this->owner);

    $this->actingAs($owner, 'web')->delete(tenantUrl($this->tenant, "knowledge/folders/{$parent->id}"))->assertRedirect();

    asTenant($this->tenant, function () use ($item, $child) {
        expect($item->fresh()->knowledge_folder_id)->toBeNull()
            ->and($item->fresh()->knowledge_domain_id)->toBe($this->general->id)
            ->and(KnowledgeFolder::query()->find($child->id))->toBeNull();
    });

    $this->actingAs($owner, 'web')->delete(tenantUrl($this->tenant, "knowledge/domains/{$this->general->id}"))->assertSessionHas('error');
    expect(asTenant($this->tenant, fn () => KnowledgeDomain::query()->find($this->general->id)))->not->toBeNull();
});

it('lets owners restrict a domain to departments', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'knowledge/domains'), [
        'name' => 'Jurídico', 'restricted' => true, 'department_ids' => [$this->finance->id], 'color' => '#334155',
    ])->assertRedirect();

    asTenant($this->tenant, function () {
        $legal = KnowledgeDomain::query()->where('slug', 'juridico')->firstOrFail();
        $access = app(KnowledgeAccess::class);

        expect($legal->department_ids)->toBe([$this->finance->id])
            ->and($access->canOpen($this->member, $legal))->toBeTrue()
            ->and($access->canOpen($this->hrManager, $legal))->toBeFalse()
            ->and($access->canOpen($this->owner, $legal))->toBeTrue();
    });

    $this->actingAs(asTenant($this->tenant, fn () => $this->financeManager), 'web')->get(tenantUrl($this->tenant, 'knowledge/domains'))->assertForbidden();
});

it('renders the knowledge pages', function (string $path, string $component) {
    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->get(tenantUrl($this->tenant, $path))->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with([
    ['knowledge', 'Knowledge/Index'],
    ['knowledge?domain=rh', 'Knowledge/Index'],
    ['knowledge?q=cimento', 'Knowledge/Index'],
    ['knowledge/new?domain=geral', 'Knowledge/Edit'],
    ['knowledge/domains', 'Knowledge/Domains'],
    ['documents', 'Documents/Index'],
    ['settings/brand', 'Settings/Brand'],
]);
