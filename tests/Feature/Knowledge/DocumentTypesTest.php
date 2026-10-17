<?php

use App\Ai\Capabilities\CapabilityCatalog;
use App\Documents\Brand;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
use App\Documents\DocumentType;
use App\Documents\Renderers\PdfRenderer;
use App\Enums\AutonomyLevel;
use App\Enums\QuestionStatus;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\GeneratedDocument;
use App\Models\Skill;
use App\Models\SkillFile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// A kind of document is a skill: its instructions say what goes in, a block
// at the top says how it looks, and images come from the skill or the brand.
// What is missing the agent asks for on a card (docs/CAPACIDADES.md, "Tipos de documento").

const PROPOSAL_SKILL = <<<'MD'
---
modelo: relatorio
estilo: estilo.css
asset assinatura: Assinatura de quem assina a proposta
---
# Proposta comercial

Enquadramento, âmbito, proposta financeira com subtotal, IVA e total, condições.
Termina com ![Assinatura](asset:assinatura).
MD;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['name' => 'Rethink', 'settings' => ['brand' => ['color' => '#0052CC']]]);

    asTenant($this->tenant, function () {
        $this->manager = User::factory()->role(Role::Manager)->create();
        app(CapabilityCatalog::class)->syncLocal();
        $this->agent = Agent::factory()->level(AutonomyLevel::ExecuteWithinLimits)->create(['reports_to_user_id' => $this->manager->id]);
        $this->skill = Skill::factory()->create(['key' => 'proposta-comercial', 'name' => 'Proposta comercial', 'instructions' => PROPOSAL_SKILL, 'all_agents' => true]);
        Storage::disk('local')->put('skills/test/estilo.css', 'h2 { color: #FF0000; } @import url("https://x.test/a.css"); </style><script>');
        SkillFile::factory()->create(['skill_id' => $this->skill->id, 'filename' => 'estilo.css', 'path' => 'skills/test/estilo.css', 'mime' => 'text/css']);
    });
});

it('reads how a document type looks from the top of its skill', function () {
    asTenant($this->tenant, function () {
        $type = DocumentType::fromSkill($this->skill);

        expect($type->template)->toBe(DocumentTemplate::Report)
            ->and($type->required)->toBe(['assinatura' => 'Assinatura de quem assina a proposta'])
            ->and($type->css)->toContain('h2 { color: #FF0000; }')->not->toContain('@import')->not->toContain('</style>')
            ->and(DocumentType::referenced('![A](asset:Carimbo.png) e ![](<asset:logo-parceiro>)'))->toBe(['carimbo', 'logo-parceiro'])
            ->and($type->missing('![C](asset:carimbo)', Brand::current(), ['carimbo']))->toBe([['key' => 'assinatura', 'label' => 'Assinatura de quem assina a proposta']]);
    });
});

it('asks for a missing image on a card, keeps it in the brand, then builds the document with it', function () {
    $content = "## Proposta financeira\n\n- **Subtotal:** 100,00 MZN\n- **Total:** 116,00 MZN\n\n![Assinatura](asset:assinatura)";

    $result = asTenant($this->tenant, fn () => runCapability($this->agent, 'documents.generate', ['format' => 'pdf', 'title' => 'Proposta Olankha', 'content' => $content, 'skill' => 'proposta-comercial']));

    expect($result->ok)->toBeTrue()->and($result->content)->toContain('Faltam imagens')->toContain('asset:assinatura');

    $question = asTenant($this->tenant, fn () => AgentQuestion::query()->sole());
    expect($question->status)->toBe(QuestionStatus::Pending)
        ->and($question->questions[0]['kind'])->toBe('file')
        ->and($question->questions[0]['asset']['key'])->toBe('assinatura')
        ->and(asTenant($this->tenant, fn () => GeneratedDocument::query()->count()))->toBe(0);

    $this->actingAs(asTenant($this->tenant, fn () => $this->manager), 'web')
        ->post(tenantUrl($this->tenant, "questions/{$question->id}/answer"), ['answers' => [['file' => UploadedFile::fake()->image('assinatura-dir.png', 300, 100)]]])
        ->assertRedirect()->assertSessionHasNoErrors();

    asTenant($this->tenant->fresh(), function () use ($content) {
        expect(AgentQuestion::query()->sole()->answers[0]['file'])->toBe('assinatura')
            ->and(array_keys(Brand::current()->assets))->toBe(['assinatura']);

        $result = runCapability($this->agent, 'documents.generate', ['format' => 'pdf', 'title' => 'Proposta Olankha', 'content' => $content, 'skill' => 'proposta-comercial']);
        expect($result->ok)->toBeTrue();

        $document = GeneratedDocument::query()->sole();
        expect($document->skill_id)->toBe($this->skill->id)->and($document->template)->toBe('relatorio')
            ->and((string) Storage::disk('local')->get($document->path))->toContain('/Subtype /Image');

        $html = PdfRenderer::polishFor(new DocumentSpec('Proposta', $content, type: DocumentType::fromSkill($this->skill)), Brand::current());
        expect($html)->toContain('<p class="figure"><img src="data:image/png;base64,')->toContain('class="asset"');
    });
});

it('goes without an image when the person says so', function () {
    $content = "Texto.\n\n![Assinatura](asset:assinatura)";
    asTenant($this->tenant, fn () => runCapability($this->agent, 'documents.generate', ['format' => 'pdf', 'title' => 'Proposta', 'content' => $content, 'skill' => 'proposta-comercial']));
    $question = asTenant($this->tenant, fn () => AgentQuestion::query()->sole());

    $this->actingAs(asTenant($this->tenant, fn () => $this->manager), 'web')
        ->post(tenantUrl($this->tenant, "questions/{$question->id}/answer"), ['answers' => [['other' => 'Seguir sem esta imagem.']]])
        ->assertSessionHasNoErrors();

    asTenant($this->tenant, function () use ($content) {
        expect(Brand::current()->assets)->toBe([]);

        $result = runCapability($this->agent, 'documents.generate', ['format' => 'pdf', 'title' => 'Proposta', 'content' => $content, 'skill' => 'proposta-comercial', 'without_assets' => ['assinatura']]);
        expect($result->ok)->toBeTrue()->and(GeneratedDocument::query()->count())->toBe(1)
            ->and(PdfRenderer::polishFor(new DocumentSpec('Proposta', $content)))->not->toContain('<img');
    });
});

it('refuses a skill the agent does not have', function () {
    $result = asTenant($this->tenant, fn () => runCapability($this->agent, 'documents.generate', ['format' => 'pdf', 'title' => 'X', 'content' => 'Y', 'skill' => 'nao-existe']));

    expect($result->ok)->toBeFalse()->and($result->content)->toContain('nenhuma skill');
});

it('lets the owner add and remove images for documents in the brand', function () {
    $owner = asTenant($this->tenant, fn () => User::factory()->owner()->create());

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'settings/brand/assets'), ['label' => 'Carimbo da empresa', 'file' => UploadedFile::fake()->image('c.jpg')])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(asTenant($this->tenant->fresh(), fn () => Brand::current()->assets['carimbo-da-empresa']['label'] ?? null))->toBe('Carimbo da empresa');
    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, 'settings/brand/assets/carimbo-da-empresa'))->assertOk();

    $this->actingAs(asTenant($this->tenant, fn () => $this->manager), 'web')->delete(tenantUrl($this->tenant, 'settings/brand/assets/carimbo-da-empresa'))->assertForbidden();
    $this->actingAs($owner, 'web')->delete(tenantUrl($this->tenant, 'settings/brand/assets/carimbo-da-empresa'))->assertRedirect();
    expect(asTenant($this->tenant->fresh(), fn () => Brand::current()->assets))->toBe([]);
});
