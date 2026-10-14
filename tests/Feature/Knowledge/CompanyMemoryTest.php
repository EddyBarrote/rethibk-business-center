<?php

use App\Ai\Agents\InstructionComposer;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Memory\CompanyMemory;
use App\Ai\Memory\MemoryConsolidator;
use App\Ai\Memory\MemoryExtractor;
use App\Enums\CompanySection;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\CompanyFact;
use App\Models\Department;
use App\Models\KnowledgeDomain;
use App\Models\Tenant;
use App\Models\User;
use App\Tasks\TaskThread;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

// The company memory (docs/DECISOES.md, 12.10.2026): curated facts every
// agent always reads; agents propose, a person approves.

beforeEach(function () {
    Queue::fake();
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->hr = Department::factory()->create(['name' => 'Direcção de RH', 'slug' => 'direccao-de-rh']);
        $this->finance = Department::factory()->create(['name' => 'Direcção Financeira', 'slug' => 'direccao-financeira']);
        $this->owner = User::factory()->owner()->create();
        $this->hrManager = User::factory()->role(Role::Manager)->create(['department_id' => $this->hr->id]);
        $this->member = User::factory()->role(Role::Member)->create(['department_id' => $this->finance->id]);
        app(KnowledgeAccess::class)->ensureDefaults();
        app(CapabilityCatalog::class)->syncLocal();
        $this->rh = KnowledgeDomain::query()->where('slug', 'rh')->firstOrFail();
        $this->financeAgent = Agent::factory()->create(['name' => 'Finanças', 'department_id' => $this->finance->id, 'reports_to_user_id' => $this->owner->id]);
        $this->hrAgent = Agent::factory()->create(['name' => 'RH', 'department_id' => $this->hr->id]);
    });
});

it('puts the published facts in every agent\'s prompt, by section, and domain facts only where the domain opens', function () {
    asTenant($this->tenant, function () {
        CompanyFact::factory()->create(['section' => CompanySection::About, 'content' => 'A MicoMoc faz obras de construção civil em Maputo.']);
        CompanyFact::factory()->create(['section' => CompanySection::Pricing, 'content' => 'A margem mínima por obra é 15%.']);
        CompanyFact::factory()->create(['section' => CompanySection::People, 'content' => 'Os salários são processados a dia 25.', 'knowledge_domain_id' => $this->rh->id]);
        CompanyFact::factory()->pending()->create(['content' => 'Ainda por aprovar.']);

        $finance = app(InstructionComposer::class)->for($this->financeAgent);
        $hr = app(InstructionComposer::class)->for($this->hrAgent);

        expect($finance)->toContain('## Memória da empresa')
            ->toContain("### Quem somos\n- A MicoMoc faz obras")
            ->toContain("### Preços e condições\n- A margem mínima")
            ->not->toContain('dia 25')
            ->not->toContain('Ainda por aprovar')
            ->and(strpos($finance, 'Quem somos'))->toBeLessThan(strpos($finance, 'Preços e condições'))
            ->and($hr)->toContain('dia 25');
    });
});

it('leaves the oldest facts out past the size limit, and says so', function () {
    $this->tenant->update(['settings' => ['business' => ['company_memory_chars' => 500]]]);

    asTenant($this->tenant->fresh(), function () {
        CompanyFact::factory()->create(['content' => 'Facto antigo '.str_repeat('a', 300)]);
        CompanyFact::factory()->create(['content' => 'Facto novo '.str_repeat('b', 300)]);

        expect(app(CompanyMemory::class)->forPrompt($this->financeAgent))
            ->toContain('Facto novo')
            ->not->toContain('Facto antigo')
            ->toContain('Ficaram de fora 1 factos');
    });
});

it('lets an agent propose a fact that waits for a person, then a curator approves an edited version that replaces the old one', function () {
    [$old, $proposal] = asTenant($this->tenant, function () {
        $old = CompanyFact::factory()->create(['section' => CompanySection::Clients, 'content' => 'A Mozal paga a 60 dias.']);
        $result = runCapability($this->financeAgent, 'company.propose_fact', ['section' => 'clients', 'content' => 'A Mozal passou a pagar a 30 dias.', 'replaces_id' => $old->id]);
        $again = runCapability($this->financeAgent, 'company.propose_fact', ['section' => 'clients', 'content' => 'A Mozal passou a pagar a 30 dias.']);

        expect($result->ok)->toBeTrue()
            ->and(CompanyFact::query()->where('status', CompanyFact::PENDING)->count())->toBe(1)
            ->and($again->data['fact_id'])->toBe($result->data['fact_id'])
            ->and($this->owner->notifications()->count())->toBe(1)
            ->and(app(InstructionComposer::class)->for($this->financeAgent))->toContain('60 dias')->not->toContain('30 dias');

        return [$old, CompanyFact::query()->findOrFail($result->data['fact_id'])];
    });

    // A member cannot approve; a manager cannot approve a fact for the whole company.
    $this->actingAs($this->member, 'web')->post(tenantUrl($this->tenant, "settings/company-memory/{$proposal->id}/approve"), ['section' => 'clients', 'content' => 'x'])->assertForbidden();
    $this->actingAs($this->hrManager, 'web')->post(tenantUrl($this->tenant, "settings/company-memory/{$proposal->id}/approve"), ['section' => 'clients', 'content' => 'x'])->assertForbidden();

    $this->actingAs($this->owner, 'web')->post(tenantUrl($this->tenant, "settings/company-memory/{$proposal->id}/approve"), [
        'section' => 'clients',
        'content' => 'A Mozal paga a 30 dias desde Outubro de 2026.',
    ])->assertRedirect();

    asTenant($this->tenant, function () use ($old, $proposal) {
        expect($proposal->fresh()->status)->toBe(CompanyFact::PUBLISHED)
            ->and($proposal->fresh()->reviewed_by_user_id)->toBe($this->owner->id)
            ->and($old->fresh()->status)->toBe(CompanyFact::SUPERSEDED)
            ->and(app(InstructionComposer::class)->for($this->financeAgent))->toContain('30 dias desde Outubro')->not->toContain('60 dias');
    });
});

it('turns what the agent learned for the whole company into a proposal when it consolidates its memory', function () {
    MemoryExtractor::fake([[
        'entries' => [
            ['section' => 'topics', 'title' => 'Compras', 'description' => 'Regras de compras.', 'body' => '- Acima de 100 000 MT, três cotações.', 'kind' => 'work'],
            ['section' => 'preferences', 'title' => 'Preferências', 'description' => 'Como gosta de receber o trabalho.', 'body' => '- Relatórios em PDF.', 'kind' => 'personal'],
        ],
        'company' => [
            ['content' => 'As compras acima de 100 000 MT precisam de três cotações.', 'section' => 'rules'],
        ],
    ]]);

    asTenant($this->tenant, function () {
        $thread = app(TaskThread::class);
        $chat = $thread->conversation($this->member, $this->financeAgent);
        $thread->post($chat, $this->member, 'A partir de agora, compras acima de 100 000 MT precisam de três cotações.', wake: false);

        expect(app(MemoryConsolidator::class)->consolidate($chat))->toBe(2);

        $proposal = CompanyFact::query()->sole();
        expect($proposal->status)->toBe(CompanyFact::PENDING)
            ->and($proposal->section)->toBe(CompanySection::Rules)
            ->and($proposal->proposed_by_agent_id)->toBe($this->financeAgent->id)
            ->and($proposal->task_id)->toBe($chat->id);
    });
});

it('shows each person the facts they may see, and the proposals only to who can approve them', function () {
    asTenant($this->tenant, function () {
        CompanyFact::factory()->create(['content' => 'Facto de todos.']);
        CompanyFact::factory()->create(['content' => 'Facto de RH.', 'knowledge_domain_id' => $this->rh->id]);
        CompanyFact::factory()->pending()->create(['content' => 'Proposta.', 'proposed_by_agent_id' => $this->financeAgent->id]);
    });

    $this->actingAs($this->member, 'web')->get(tenantUrl($this->tenant, 'settings/company-memory'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Settings/CompanyMemory')
            ->has('facts', 1)
            ->where('facts.0.content', 'Facto de todos.')
            ->where('facts.0.can_edit', false)
            ->has('proposals', 0)
            ->where('can.write', false));

    $this->actingAs($this->owner, 'web')->get(tenantUrl($this->tenant, 'settings/company-memory'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('facts', 2)->has('proposals', 1)->where('proposals.0.proposed_by', 'Finanças'));

    // It moved from Conhecimento to Definições › Memória; old links still work.
    $this->actingAs($this->member, 'web')->get(tenantUrl($this->tenant, 'knowledge/company'))->assertRedirect('/settings/company-memory');

    // The HR manager writes HR facts, not facts for everyone.
    $this->actingAs($this->hrManager, 'web')->post(tenantUrl($this->tenant, 'settings/company-memory'), ['section' => 'people', 'content' => 'Férias marcam-se até Março.'])->assertForbidden();
    $this->actingAs($this->hrManager, 'web')->post(tenantUrl($this->tenant, 'settings/company-memory'), ['section' => 'people', 'content' => 'Férias marcam-se até Março.', 'knowledge_domain_id' => $this->rh->id])->assertRedirect();

    asTenant($this->tenant, fn () => expect(CompanyFact::query()->where('content', 'Férias marcam-se até Março.')->sole()->created_by_user_id)->toBe($this->hrManager->id));
});
