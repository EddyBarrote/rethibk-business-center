<?php

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Documents\Brand;
use App\Documents\MarkdownBlocks;
use App\Documents\Renderers\XlsxRenderer;
use App\Enums\AutonomyLevel;
use App\Enums\Role;
use App\Models\Agent;
use App\Models\Department;
use App\Models\GeneratedDocument;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeItem;
use App\Models\Report;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpPresentation\IOFactory as Presentations;
use PhpOffice\PhpSpreadsheet\IOFactory as Spreadsheets;

// Agents (and people) turn Markdown into branded Word, PowerPoint, Excel
// and PDF files (docs/CONHECIMENTO.md).

const MONTH_CLOSE = <<<'MD'
# Fecho de Setembro

Receita acima do previsto.

## Indicadores

| Indicador | Valor | Variação |
|---|---|---|
| Receita | 12 450 000,00 | 12% |
| Custos | 8.300.120,50 | -3% |
| NUIT | 0400123 | — |

## Riscos

- Atraso da **Mozal**
- Câmbio do USD
MD;

beforeEach(function () {
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create(['name' => 'MICOMOC', 'settings' => ['brand' => ['color' => '#C2410C']]]);

    asTenant($this->tenant, function () {
        $this->department = Department::factory()->create();
        $this->owner = User::factory()->owner()->create();
        $this->manager = User::factory()->role(Role::Manager)->create(['department_id' => $this->department->id]);
        $this->outsider = User::factory()->role(Role::Member)->create();
        app(CapabilityCatalog::class)->syncLocal();
        $this->agent = Agent::factory()->level(AutonomyLevel::ExecuteWithinLimits)->create(['department_id' => $this->department->id, 'reports_to_user_id' => $this->manager->id]);
    });
});

function generated(array $arguments): GeneratedDocument
{
    $result = runCapability(test()->agent, 'documents.generate', $arguments);
    expect($result->ok)->toBeTrue();

    return GeneratedDocument::query()->findOrFail($result->data['document_id']);
}

it('generates a branded Word document from Markdown', function () {
    asTenant($this->tenant, function () {
        $doc = generated(['format' => 'docx', 'title' => 'Fecho de Setembro', 'content' => MONTH_CLOSE, 'template' => 'relatorio']);
        $path = Storage::disk('local')->path($doc->path);

        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $body = (string) $zip->getFromName('word/document.xml');
        $styles = (string) $zip->getFromName('word/styles.xml');

        expect($body)->toContain('Indicadores')->toContain('12 450 000,00')->toContain('<w:tbl>')
            ->and($styles)->toContain('C2410C')
            ->and(collect(range(0, $zip->numFiles - 1))->map(fn ($i) => (string) $zip->getNameIndex($i))->filter(fn ($n) => str_starts_with($n, 'word/header'))->map(fn ($n) => (string) $zip->getFromName($n))->implode(''))->toContain('MICOMOC')
            ->and($doc->filename)->toBe('fecho-de-setembro.docx')
            ->and($doc->agent_id)->toBe($this->agent->id);
    });
});

it('generates a PDF, a presentation and a spreadsheet', function () {
    asTenant($this->tenant, function () {
        $pdf = generated(['format' => 'pdf', 'title' => 'Fecho', 'content' => MONTH_CLOSE]);
        expect(substr((string) Storage::disk('local')->get($pdf->path), 0, 5))->toBe('%PDF-');

        $pptx = generated(['format' => 'pptx', 'title' => 'Fecho de Setembro', 'content' => MONTH_CLOSE]);
        $deck = Presentations::createReader('PowerPoint2007')->load(Storage::disk('local')->path($pptx->path));
        // Cover, the intro paragraph, Indicadores (table) and Riscos.
        expect($deck->getSlideCount())->toBe(4);

        $xlsx = generated(['format' => 'xlsx', 'title' => 'Fecho de Setembro', 'content' => MONTH_CLOSE]);
        $book = Spreadsheets::load(Storage::disk('local')->path($xlsx->path));
        $sheet = $book->getSheetByName('Indicadores');

        expect($book->getSheetNames())->toBe(['Indicadores', 'Notas'])
            ->and($sheet->getCell('B2')->getValue())->toBe(12450000.0)
            ->and($sheet->getCell('B3')->getValue())->toBe(8300120.5)
            ->and($sheet->getCell('C2')->getValue())->toBe(0.12)
            ->and($sheet->getCell('B4')->getValue())->toBe('0400123')
            ->and($sheet->getStyle('A1')->getFill()->getStartColor()->getRGB())->toBe('C2410C');
    });
});

it('files a generated document in the knowledge base, under review for a low-autonomy agent', function () {
    asTenant($this->tenant, function () {
        $this->agent->update(['autonomy_level' => AutonomyLevel::Suggest]);
        app(KnowledgeAccess::class)->ensureDefaults();

        $result = runCapability($this->agent->fresh(), 'documents.generate', [
            'format' => 'pdf', 'title' => 'Política de compras', 'content' => "## Regras\n\nTrês cotações acima de 50 000 MZN.",
            'file_in_knowledge' => true, 'domain' => 'Operações', 'folder' => 'Políticas',
        ]);

        $item = KnowledgeItem::query()->findOrFail($result->data['knowledge_item_id']);
        expect($item->status)->toBe('pending_review')
            ->and($item->filename)->toBe('politica-de-compras.pdf')
            ->and($item->content)->toContain('Três cotações')
            ->and($item->folder->name)->toBe('Políticas')
            ->and(GeneratedDocument::query()->findOrFail($result->data['document_id'])->knowledge_item_id)->toBe($item->id);
        Storage::disk('local')->assertExists($item->path);
    });
});

it('shows a generated file to the people around the agent, not to everyone', function () {
    $doc = asTenant($this->tenant, fn () => generated(['format' => 'xlsx', 'title' => 'Mapa', 'content' => MONTH_CLOSE]));
    [$manager, $outsider, $owner] = asTenant($this->tenant, fn () => [$this->manager, $this->outsider, $this->owner]);

    $this->actingAs($manager, 'web')->get(tenantUrl($this->tenant, "documents/{$doc->id}/download"))->assertOk()->assertDownload('mapa.xlsx');
    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, "documents/{$doc->id}"))->assertOk()->assertInertia(fn ($page) => $page->component('Documents/Show'));
    $this->actingAs($outsider, 'web')->get(tenantUrl($this->tenant, "documents/{$doc->id}/download"))->assertForbidden();
    $this->actingAs($outsider, 'web')->get(tenantUrl($this->tenant, 'documents'))->assertInertia(fn ($page) => $page->where('documents.data', []));
});

it('lets a person generate, convert and file a document, and export a report', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'documents'), [
        'title' => 'Proposta', 'content' => MONTH_CLOSE, 'format' => 'docx', 'template' => 'carta',
    ])->assertRedirect();
    $doc = asTenant($this->tenant, fn () => GeneratedDocument::query()->latest('id')->firstOrFail());
    expect($doc->created_by_user_id)->toBe($owner->id)->and($doc->template)->toBe('carta');

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, "documents/{$doc->id}/convert"), ['format' => 'pdf'])->assertRedirect();
    expect(asTenant($this->tenant, fn () => GeneratedDocument::query()->where('format', 'pdf')->count()))->toBe(1);

    $domain = asTenant($this->tenant, function () {
        app(KnowledgeAccess::class)->ensureDefaults();

        return KnowledgeDomain::query()->where('slug', 'clientes')->firstOrFail();
    });
    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, "documents/{$doc->id}/file"), ['domain_id' => $domain->id])->assertRedirect();
    expect(asTenant($this->tenant, fn () => $doc->fresh()->knowledgeItem->knowledge_domain_id))->toBe($domain->id);

    $report = asTenant($this->tenant, fn () => Report::factory()->create(['title' => 'Mapa comparativo', 'content' => MONTH_CLOSE]));
    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, "reports/{$report->id}/export"), ['format' => 'pptx'])->assertRedirect();
    expect(asTenant($this->tenant, fn () => GeneratedDocument::query()->where('title', 'Mapa comparativo')->value('format')))->toBe('pptx');
});

it('saves the brand that documents use', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'settings/brand'), [
        'color' => '#0052cc',
        'footer' => 'MICOMOC, Lda · NUIT 400123456',
        'logo' => UploadedFile::fake()->image('logo.png', 200, 60),
    ])->assertRedirect()->assertSessionHasNoErrors();

    asTenant($this->tenant->fresh(), function () {
        $brand = Brand::current();
        expect($brand->color)->toBe('#0052CC')->and($brand->footer)->toContain('NUIT')->and($brand->logoPath)->not->toBeNull();

        $doc = generated(['format' => 'docx', 'title' => 'Com logótipo', 'content' => 'Texto.']);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($doc->path));
        expect(collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->filter(fn ($n) => str_starts_with($n, 'word/media/'))->count())->toBeGreaterThan(0);
    });

    $this->actingAs(asTenant($this->tenant, fn () => $this->manager), 'web')->post(tenantUrl($this->tenant, 'settings/brand'), ['color' => '#000000'])->assertForbidden();
});

it('reads Markdown blocks and spreadsheet numbers', function () {
    $blocks = MarkdownBlocks::parse(MONTH_CLOSE);

    expect(array_column($blocks, 'type'))->toBe(['heading', 'paragraph', 'heading', 'table', 'heading', 'list'])
        ->and($blocks[3]['rows'][0])->toBe(['Receita', '12 450 000,00', '12%'])
        ->and($blocks[5]['items'][0])->toBe('Atraso da Mozal')
        ->and(XlsxRenderer::number('1,200.50'))->toBe(['value' => 1200.5, 'format' => '#,##0.00'])
        ->and(XlsxRenderer::number('MZN 45 000'))->toBe(['value' => 45000, 'format' => '#,##0'])
        ->and(XlsxRenderer::number('3,5%'))->toBe(['value' => 0.035, 'format' => '0.0%'])
        ->and(XlsxRenderer::number('2026-10-04'))->toBeNull()
        ->and(XlsxRenderer::number('0841234567'))->toBeNull();
});
