<?php

use App\Ai\Budget\BudgetGuard;
use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Knowledge\PageReader;
use App\Ai\Runs\AgentRunner;
use App\Enums\AutonomyLevel;
use App\Enums\KnowledgeType;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AiCharge;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeEmbedding;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\Tenant;
use App\Models\User;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;

// The company's file repository in the knowledge base (docs/DECISOES.md,
// 14.10.2026): files read page by page on the queue, scanned pages read by a
// model, uploads in parts with folders, versions, hybrid search, and what
// agents do with files.

beforeEach(function () {
    config(['ai.providers.openai.key' => null, 'ai.providers.gemini.key' => null]);
    Storage::fake('local');
    $this->tenant = Tenant::factory()->create();

    asTenant($this->tenant, function () {
        $this->owner = User::factory()->owner()->create();
        app(KnowledgeAccess::class)->ensureDefaults();
        $this->general = KnowledgeDomain::query()->where('slug', 'geral')->firstOrFail();
    });
});

/**
 * A PDF with one page per text; an empty text is a page with nothing on it,
 * like a scan.
 *
 * @param  list<string>  $pages
 */
function pdfWithPages(array $pages): string
{
    $html = implode('<div style="page-break-before: always"></div>', array_map(fn (string $text) => '<p>'.e($text).'</p>', $pages));
    $dompdf = new Dompdf;
    $dompdf->loadHtml("<html><body>{$html}</body></html>");
    $dompdf->render();
    $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
    file_put_contents($path, (string) $dompdf->output());

    return $path;
}

function poppler(): bool
{
    return Process::run(['which', 'pdfseparate'])->successful();
}

/** Sends a file in parts to /files for the repository, as the screen does. */
function stageForRepository($test, User $user, Tenant $tenant, string $path, string $name): int
{
    $bytes = (string) file_get_contents($path);
    $id = $test->actingAs($user, 'web')->postJson(tenantUrl($tenant, 'files'), ['filename' => $name, 'size' => strlen($bytes), 'source' => 'repository'])
        ->assertCreated()->json('id');
    $test->actingAs($user, 'web')->post(tenantUrl($tenant, "files/{$id}/chunk"), ['offset' => 0, 'chunk' => UploadedFile::fake()->createWithContent($name, $bytes)])
        ->assertOk()->assertJson(['ready' => true]);

    return (int) $id;
}

it('reads a PDF page by page, has a model read the scanned page and finds the page of an excerpt', function () {
    config(['ai.default' => 'gemini', 'ai.providers.gemini.key' => 'test']);
    Embeddings::fake();
    PageReader::fake(["[Página 2]\nFactura digitalizada INV-2026077 da Triple Vision, total 45 000 MT.\n[carimbo: PAGO]"]);
    $pdf = pdfWithPages([str_repeat('Contrato de fornecimento de cimento Portland. ', 8), '', str_repeat('Cláusula de pagamento a 60 dias. ', 8)]);

    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->post(tenantUrl($this->tenant, 'knowledge/upload'), [
        'files' => [new UploadedFile($pdf, 'contrato.pdf', 'application/pdf', null, true)],
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
    ])->assertRedirect()->assertSessionHasNoErrors();

    asTenant($this->tenant, function () {
        $item = KnowledgeItem::query()->where('filename', 'contrato.pdf')->firstOrFail();

        expect($item->pages)->toBe(3)
            ->and($item->reading_status)->toBe('done')
            ->and($item->text_source)->toBe('mixed')
            ->and($item->content)->toContain("[Página 2]\nFactura digitalizada INV-2026077")->toContain('[Página 3]')
            ->and($item->pageTexts()->where('source', 'model')->pluck('page')->all())->toBe([2])
            ->and($item->content_hash)->toHaveLength(64)
            ->and(AiCharge::query()->where('purpose', 'knowledge.reading')->where('subject_id', $item->id)->count())->toBe(1)
            ->and(KnowledgeEmbedding::query()->where('knowledge_item_id', $item->id)->whereNotNull('vector')->exists())->toBeTrue();

        Storage::disk('local')->assertExists((string) $item->thumbnail_path);

        $hit = app(KnowledgeBase::class)->search('INV-2026077', 5, $this->owner)->first();
        expect($hit['item']->id)->toBe($item->id)->and($hit['page'])->toBe(2);

        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->create();
        $page = runCapability($agent, 'knowledge.read', ['id' => $item->id, 'pages' => '3']);
        expect($page->content)->toContain('[Página 3]')->toContain('Cláusula de pagamento')->not->toContain('Factura digitalizada');
        expect(runCapability($agent, 'memory.search', ['query' => 'INV-2026077'])->content)->toContain('excerto da p. 2');
    });

    $item = asTenant($this->tenant, fn () => KnowledgeItem::query()->where('filename', 'contrato.pdf')->firstOrFail());
    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->get(tenantUrl($this->tenant, "knowledge/{$item->id}/thumbnail"))->assertOk()->assertHeader('content-type', 'image/png');
    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->get(tenantUrl($this->tenant, "knowledge/{$item->id}?page=2"))
        ->assertInertia(fn ($page) => $page->component('Knowledge/Show')->where('page', 2)->where('item.text_source', 'mixed')->where('item.file.pages', 3));
})->skip(fn () => ! poppler(), 'Precisa do Poppler (pdftotext, pdfseparate).');

it('keeps scanned pages without text, and says so, when no model can read them', function () {
    $pdf = pdfWithPages(['', '']);

    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->post(tenantUrl($this->tenant, 'knowledge/upload'), [
        'files' => [new UploadedFile($pdf, 'digitalizado.pdf', 'application/pdf', null, true)],
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
    ])->assertRedirect();

    asTenant($this->tenant, function () {
        $item = KnowledgeItem::query()->where('filename', 'digitalizado.pdf')->firstOrFail();

        expect($item->reading_status)->toBe('done')
            ->and($item->text_source)->toBe('none')
            ->and($item->reading_note)->toContain('2 páginas sem texto')
            ->and(AiCharge::query()->count())->toBe(0);
    });
})->skip(fn () => ! poppler(), 'Precisa do Poppler.');

it('files a dragged folder sent in parts, keeps its folders and skips a file already there', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);
    $pdf = pdfWithPages(['Caderno de encargos da obra de Nacala, com prazos e penalidades.']);
    $notes = tempnam(sys_get_temp_dir(), 'txt');
    file_put_contents($notes, 'Notas da visita ao estaleiro de Nacala.');

    $first = stageForRepository($this, $owner, $this->tenant, $pdf, 'caderno.pdf');
    $second = stageForRepository($this, $owner, $this->tenant, $notes, 'notas.txt');

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'knowledge/upload'), [
        'uploads' => [['id' => $first, 'path' => 'Obra Nacala/Contratos/caderno.pdf'], ['id' => $second, 'path' => 'Obra Nacala/notas.txt']],
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
    ])->assertRedirect()->assertSessionHas('success');

    asTenant($this->tenant, function () {
        $contracts = KnowledgeFolder::query()->where('name', 'Contratos')->firstOrFail();

        expect($contracts->path())->toBe('Obra Nacala / Contratos')
            ->and(KnowledgeItem::query()->where('filename', 'caderno.pdf')->value('knowledge_folder_id'))->toBe($contracts->id)
            ->and(KnowledgeItem::query()->where('filename', 'notas.txt')->first()->folder->name)->toBe('Obra Nacala')
            ->and(KnowledgeItem::query()->where('filename', 'notas.txt')->value('content'))->toContain('estaleiro de Nacala')
            ->and(MessageAttachment::query()->where('source', 'repository')->count())->toBe(0);
    });

    $again = stageForRepository($this, $owner, $this->tenant, $pdf, 'copia.pdf');
    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'knowledge/upload'), [
        'uploads' => [['id' => $again, 'path' => null]],
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
    ])->assertSessionHas('error', fn (string $message) => str_contains($message, 'já existia'));

    expect(asTenant($this->tenant, fn () => KnowledgeItem::query()->count()))->toBe(2);
});

it('refuses repository uploads to people who cannot write in the knowledge base', function () {
    $member = asTenant($this->tenant, fn () => User::factory()->role(Role::Member)->create(['permission_overrides' => [Permission::WriteKnowledge->value => false]]));

    $this->actingAs($member, 'web')->postJson(tenantUrl($this->tenant, 'files'), ['filename' => 'a.pdf', 'size' => 10, 'source' => 'repository'])->assertForbidden();
    $this->actingAs($member, 'web')->postJson(tenantUrl($this->tenant, 'files'), ['filename' => 'a.exe', 'size' => 10, 'source' => 'repository'])->assertForbidden();
});

it('keeps the earlier version of a file when a new one is uploaded', function () {
    $owner = asTenant($this->tenant, fn () => $this->owner);
    $v1 = pdfWithPages(['Tabela de preços de 2025: cimento a 600 MT.']);
    $v2 = pdfWithPages(['Tabela de preços de 2026: cimento a 650 MT.']);

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, 'knowledge/upload'), [
        'files' => [new UploadedFile($v1, 'precos.pdf', 'application/pdf', null, true)],
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
    ]);
    $item = asTenant($this->tenant, fn () => KnowledgeItem::query()->where('filename', 'precos.pdf')->firstOrFail());
    $staged = stageForRepository($this, $owner, $this->tenant, $v2, 'precos-2026.pdf');

    $this->actingAs($owner, 'web')->post(tenantUrl($this->tenant, "knowledge/{$item->id}/version"), ['upload_id' => $staged])->assertRedirect()->assertSessionHas('success');

    asTenant($this->tenant, function () use ($item) {
        $item->refresh();
        $old = $item->versions()->firstOrFail();

        expect($item->version)->toBe(2)
            ->and($item->filename)->toBe('precos-2026.pdf')
            ->and($item->content)->toContain('650 MT')->not->toContain('600 MT')
            ->and($old->version)->toBe(1)
            ->and($old->filename)->toBe('precos.pdf');
        Storage::disk('local')->assertExists($old->path);
    });

    $old = asTenant($this->tenant, fn () => $item->versions()->firstOrFail());
    $this->actingAs($owner, 'web')->get(tenantUrl($this->tenant, "knowledge/{$item->id}/versions/{$old->id}/file"))->assertOk()->assertDownload('precos.pdf');

    // Deleting the item takes its versions with it.
    $this->actingAs($owner, 'web')->delete(tenantUrl($this->tenant, "knowledge/{$item->id}"));
    Storage::disk('local')->assertMissing($old->path);
});

it('saves a conversation attachment in the repository, by a person and by an agent', function () {
    [$task, $first, $second] = asTenant($this->tenant, function () {
        $task = Task::factory()->create(['user_id' => $this->owner->id, 'created_by_user_id' => $this->owner->id]);
        $message = TaskMessage::factory()->create(['task_id' => $task->id]);
        $files = collect(['Contrato Mozal 2026, assinado.', 'Proposta comercial para a Vale.'])->map(function (string $text, int $i) use ($task, $message) {
            $path = "attachments/test/doc{$i}.pdf";
            Storage::disk('local')->put($path, (string) file_get_contents(pdfWithPages([$text])));

            return MessageAttachment::factory()->create(['task_id' => $task->id, 'task_message_id' => $message->id, 'path' => $path, 'filename' => "doc{$i}.pdf"]);
        });

        return [$task, $files[0], $files[1]];
    });
    $owner = asTenant($this->tenant, fn () => $this->owner);
    $domain = asTenant($this->tenant, fn () => $this->general->id);

    $this->actingAs($owner, 'web')->postJson(tenantUrl($this->tenant, 'knowledge/import'), ['source' => 'attachment', 'id' => $first->id, 'domain_id' => $domain, 'title' => 'Contrato Mozal 2026'])
        ->assertCreated()->assertJson(['existed' => false, 'title' => 'Contrato Mozal 2026']);
    $this->actingAs($owner, 'web')->postJson(tenantUrl($this->tenant, 'knowledge/import'), ['source' => 'attachment', 'id' => $first->id, 'domain_id' => $domain])
        ->assertOk()->assertJson(['existed' => true]);

    // Someone who cannot open the conversation cannot file its files.
    $stranger = asTenant($this->tenant, fn () => User::factory()->role(Role::Member)->create());
    $this->actingAs($stranger, 'web')->postJson(tenantUrl($this->tenant, 'knowledge/import'), ['source' => 'attachment', 'id' => $second->id, 'domain_id' => $domain])->assertForbidden();

    asTenant($this->tenant, function () use ($task, $second) {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->level(AutonomyLevel::Suggest)->create(['reports_to_user_id' => $this->owner->id]);
        $run = app(AgentRunner::class)->create($agent, 'Guarda a proposta.', TriggerType::Manual);
        $run->forceFill(['task_id' => $task->id])->save();
        $context = new CapabilityContext($agent, $run);
        $tool = app(CapabilityRegistry::class)->find('knowledge.file');

        $result = $tool->execute(['attachment_id' => $second->id, 'title' => 'Proposta Vale', 'domain' => 'Clientes', 'folder' => 'Vale/Propostas'], $context);
        $item = KnowledgeItem::query()->findOrFail($result->data['knowledge_item_id']);

        expect($result->ok)->toBeTrue()
            ->and($item->status)->toBe('pending_review')
            ->and($item->folder->path())->toBe('Vale / Propostas')
            ->and($item->writtenByAgent())->toBeTrue()
            ->and($item->content)->toContain('Proposta comercial para a Vale');

        // Only files of its own conversation.
        $other = MessageAttachment::factory()->create();
        expect($tool->execute(['attachment_id' => $other->id], $context)->ok)->toBeFalse();
    });
});

it('puts the item with the exact words first, and searches 768-dimension binary vectors', function () {
    config(['knowledge.embedding_dimensions' => 3]);
    // Every text looks like "cimento" to the fake model, except the invoice.
    Embeddings::fake(fn (EmbeddingsPrompt $prompt) => array_map(fn (string $text) => str_contains($text, 'Factura') ? [0.0, 1.0, 0.0] : [1.0, 0.0, 0.0], $prompt->inputs));

    asTenant($this->tenant, function () {
        $kb = app(KnowledgeBase::class);
        $cement = $kb->remember(KnowledgeType::Article, 'Preços de cimento', 'O cimento Portland 42,5 custa 650 MT o saco.', null, ['knowledge_domain_id' => $this->general->id]);
        $invoice = $kb->remember(KnowledgeType::Article, 'Pagamento recebido', 'Factura FT-2026-118 paga pela Mozal a 3 de Outubro.', null, ['knowledge_domain_id' => $this->general->id]);

        $row = KnowledgeEmbedding::query()->where('knowledge_item_id', $cement->id)->firstOrFail();
        expect($row->embedding)->toBeNull()->and($row->dimensions)->toBe(3)->and(strlen((string) $row->vector))->toBe(12);

        // The query looks like cement to the vectors, but only the invoice has the words.
        expect($kb->search('FT-2026-118', 5, $this->owner)->first()['item']->id)->toBe($invoice->id)
            ->and($kb->search('preço do saco', 5, $this->owner)->first()['item']->id)->toBe($cement->id);
    });
});

it('lets an agent look at the original pages and charges the call to its budget', function () {
    config(['ai.default' => 'gemini', 'ai.providers.gemini.key' => 'test']);
    Embeddings::fake();
    PageReader::fake(['A página 1 está assinada pelos dois lados e tem o carimbo da empresa (p. 1).']);
    $pdf = pdfWithPages([str_repeat('Contrato de prestação de serviços entre as partes. ', 6), str_repeat('Anexo com a tabela de preços. ', 6)]);

    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->post(tenantUrl($this->tenant, 'knowledge/upload'), [
        'files' => [new UploadedFile($pdf, 'contrato.pdf', 'application/pdf', null, true)],
        'domain_id' => asTenant($this->tenant, fn () => $this->general->id),
    ]);

    asTenant($this->tenant, function () {
        app(CapabilityCatalog::class)->syncLocal();
        $agent = Agent::factory()->create();
        $item = KnowledgeItem::query()->where('filename', 'contrato.pdf')->firstOrFail();

        $result = runCapability($agent, 'knowledge.view', ['id' => $item->id, 'question' => 'Está assinado?', 'pages' => '1']);

        expect($result->ok)->toBeTrue()
            ->and($result->content)->toContain('assinada')->toContain('página 1')
            ->and(AiCharge::query()->where('purpose', 'knowledge.view')->where('agent_id', $agent->id)->count())->toBe(1);

        PageReader::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Está assinado?'));

        $article = KnowledgeItem::factory()->create(['knowledge_domain_id' => $this->general->id, 'status' => 'published']);
        expect(runCapability($agent, 'knowledge.view', ['id' => $article->id, 'question' => 'x'])->ok)->toBeFalse();

        AiCharge::factory()->create(['cost_usd' => 1.5, 'agent_id' => $agent->id]);
        expect(app(BudgetGuard::class)->tenantSpent())->toBeGreaterThanOrEqual(1.5)
            ->and(app(BudgetGuard::class)->agentSpent($agent))->toBeGreaterThanOrEqual(1.5);
    });
})->skip(fn () => ! poppler(), 'Precisa do Poppler.');

it('shows the repository: every file, by kind, with what the upload accepts', function () {
    asTenant($this->tenant, fn () => app(KnowledgeBase::class)->rememberFile(pdfWithPages(['Manual de segurança.']), 'manual.pdf', 'application/pdf', 'Manual', $this->owner, ['knowledge_domain_id' => $this->general->id]));

    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->get(tenantUrl($this->tenant, 'knowledge?view=files&kind=pdf'))
        ->assertInertia(fn ($page) => $page->component('Knowledge/Index')
            ->where('filters.view', 'files')
            ->where('filters.kind', 'pdf')
            ->where('files', 1)
            ->where('items.0.file.extension', 'pdf')
            ->where('upload.max_mb', 100)
            ->has('upload.chunk_bytes'));

    $this->actingAs(asTenant($this->tenant, fn () => $this->owner), 'web')->get(tenantUrl($this->tenant, 'knowledge?view=files&kind=office'))
        ->assertInertia(fn ($page) => $page->where('items', []));
});
