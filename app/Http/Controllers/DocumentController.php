<?php

namespace App\Http\Controllers;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Documents\DocumentFormat;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\Report;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Files generated from Markdown (Word, PowerPoint, Excel, PDF): by agents
 * with documents.generate, by people here, or from a report.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentGenerator $generator,
        private readonly KnowledgeAccess $access,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $format = $request->query('format');

        return Inertia::render('Documents/Index', [
            'documents' => $this->visible($user)
                ->with(['agent:id,name', 'creator:id,name'])
                ->when(in_array($format, array_column(DocumentFormat::cases(), 'value'), true), fn (Builder $q) => $q->where('format', $format))
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (GeneratedDocument $d) => $this->present($d)),
            'filter' => $format,
            'formats' => DocumentFormat::options(),
            'templates' => DocumentTemplate::options(),
        ]);
    }

    public function show(Request $request, GeneratedDocument $document): Response
    {
        $user = $this->user($request);
        abort_unless($this->canSee($user, $document), 403);
        $this->access->ensureDefaults();
        $domains = KnowledgeDomain::query()->orderBy('position')->get()->filter(fn (KnowledgeDomain $d) => $this->access->canOpen($user, $d))->values();

        return Inertia::render('Documents/Show', [
            'document' => [...$this->present($document->load(['agent:id,name', 'creator:id,name', 'knowledgeItem:id,title'])), 'source' => $document->source],
            'formats' => DocumentFormat::options(),
            'domains' => $domains->map(fn (KnowledgeDomain $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'folders' => KnowledgeFolder::query()->whereIn('knowledge_domain_id', $domains->pluck('id'))->get()
                ->map(fn (KnowledgeFolder $f) => ['id' => $f->id, 'domain_id' => $f->knowledge_domain_id, 'path' => $f->path()])->sortBy('path')->values(),
        ]);
    }

    /**
     * A person writes Markdown and gets the file.
     */
    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:200'],
            'content' => ['required', 'string', 'max:200000'],
            'format' => ['required', Rule::enum(DocumentFormat::class)],
            'template' => ['required', Rule::enum(DocumentTemplate::class)],
        ]);

        $document = $this->make($user, DocumentFormat::from($data['format']), new DocumentSpec($data['title'], $data['content'], DocumentTemplate::from($data['template']), $data['subtitle'] ?? null));

        return $document === null
            ? back()->with('error', 'Não foi possível gerar o ficheiro.')
            : to_route('documents.show', $document)->with('success', 'Ficheiro gerado.');
    }

    /**
     * The same content in another format.
     */
    public function convert(Request $request, GeneratedDocument $document): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->canSee($user, $document), 403);
        $format = DocumentFormat::from($request->validate(['format' => ['required', Rule::enum(DocumentFormat::class)]])['format']);

        $copy = $this->make($user, $format, new DocumentSpec($document->title, $document->source, DocumentTemplate::tryFrom($document->template) ?? DocumentTemplate::Document));

        return $copy === null ? back()->with('error', 'Não foi possível gerar o ficheiro.') : to_route('documents.show', $copy)->with('success', "Gerado em {$format->label()}.");
    }

    /**
     * A report drafted by an agent, exported as a file.
     */
    public function fromReport(Request $request, Report $report): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless(app(ReportController::class)->canSee($user, $report), 403);
        $format = DocumentFormat::from($request->validate(['format' => ['required', Rule::enum(DocumentFormat::class)]])['format']);

        $document = $this->make($user, $format, new DocumentSpec($report->title, $report->content, DocumentTemplate::Report));

        return $document === null ? back()->with('error', 'Não foi possível gerar o ficheiro.') : to_route('documents.show', $document)->with('success', "Exportado em {$format->label()}.");
    }

    public function file(Request $request, GeneratedDocument $document): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->canSee($user, $document), 403);
        abort_if($document->knowledge_item_id !== null, 422, 'Já está na base de conhecimento.');
        $data = $request->validate([
            'domain_id' => ['required', 'integer', TenantRule::exists('knowledge_domains')],
            'folder_id' => ['nullable', 'integer', TenantRule::exists('knowledge_folders')],
        ]);
        $domain = KnowledgeDomain::query()->findOrFail($data['domain_id']);
        abort_unless($this->access->canOpen($user, $domain), 403);
        $folder = isset($data['folder_id']) ? KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->findOrFail($data['folder_id']) : null;

        $item = $this->generator->fileInKnowledge($document, $user, ['knowledge_domain_id' => $domain->id, 'knowledge_folder_id' => $folder?->id]);
        AuditLog::record($user, 'documents.filed', ['title' => $document->title, 'domain' => $domain->name], subject: $item);

        return back()->with('success', "Arquivado em {$domain->name}.");
    }

    public function download(Request $request, GeneratedDocument $document): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($this->canSee($user, $document), 403);
        $disk = Storage::disk($document->disk);
        abort_unless($disk->exists($document->path), 404);
        $format = DocumentFormat::from($document->format);
        $headers = ['Content-Type' => $format->mime(), 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('inline') && $format === DocumentFormat::Pdf
            ? $disk->response($document->path, $document->filename, $headers, 'inline')
            : $disk->download($document->path, $document->filename, $headers);
    }

    public function destroy(Request $request, GeneratedDocument $document): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->canSee($user, $document) && ($user->isManager() || $document->created_by_user_id === $user->id), 403);
        AuditLog::record($user, 'documents.deleted', ['title' => $document->title, 'format' => $document->format]);
        Storage::disk($document->disk)->delete($document->path);
        $document->delete();

        return to_route('documents.index')->with('success', 'Ficheiro apagado.');
    }

    private function make(User $user, DocumentFormat $format, DocumentSpec $spec): ?GeneratedDocument
    {
        try {
            $document = $this->generator->generate($format, $spec, user: $user);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        AuditLog::record($user, 'documents.generated', ['title' => $document->title, 'format' => $format->value], subject: $document);

        return $document;
    }

    private function canSee(User $user, GeneratedDocument $document): bool
    {
        return $this->visible($user)->whereKey($document->id)->exists();
    }

    /**
     * Owners and admins see every file; others, their own and those of
     * agents that report to them or work in their department (as reports).
     *
     * @return Builder<GeneratedDocument>
     */
    private function visible(User $user): Builder
    {
        return GeneratedDocument::query()->when(! $user->canManageTenant(), fn (Builder $q) => $q->where(fn (Builder $w) => $w
            ->where('created_by_user_id', $user->id)
            ->orWhereHas('agent', fn (Builder $a) => $a
                ->where('reports_to_user_id', $user->id)
                ->when($user->department_id !== null, fn (Builder $d) => $d->orWhere('department_id', $user->department_id)))));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(GeneratedDocument $d): array
    {
        $format = DocumentFormat::from($d->format);

        return [
            'id' => $d->id,
            'title' => $d->title,
            'format' => $format->value,
            'format_label' => $format->label(),
            'template' => $d->template,
            'template_label' => DocumentTemplate::tryFrom($d->template)?->label(),
            'filename' => $d->filename,
            'size' => $d->size_bytes,
            'agent' => $d->agent ? ['id' => $d->agent->id, 'name' => $d->agent->name] : null,
            'creator' => $d->creator?->name,
            'knowledge_item' => $d->knowledge_item_id !== null ? ['id' => $d->knowledge_item_id] : null,
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
