<?php

namespace App\Http\Controllers;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Documents\DocumentFormat;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
use App\Documents\DocumentType;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\DocumentFolder;
use App\Models\GeneratedDocument;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\Report;
use App\Models\Skill;
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

    /** The «ordem» of the list, as the address writes it. */
    private const SORTS = ['recentes', 'antigos', 'nome', 'tamanho', 'tipo'];

    /**
     * The file manager (docs/DECISOES.md, "Gestor de ficheiros"): folders,
     * search, author (an agent or a person), format and order, all in the
     * address so a view can be shared.
     */
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $formats = array_column(DocumentFormat::cases(), 'value');
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'format' => in_array($request->query('format'), $formats, true) ? (string) $request->query('format') : null,
            'autor' => in_array($request->query('autor'), ['ia', 'pessoas'], true) ? (string) $request->query('autor') : null,
            'pasta' => $request->query('pasta') === 'sem' || ctype_digit((string) $request->query('pasta')) ? (string) $request->query('pasta') : null,
            'ordem' => in_array($request->query('ordem'), self::SORTS, true) ? (string) $request->query('ordem') : 'recentes',
        ];

        $query = $this->visible($user)
            ->with(['agent:id,name,avatar_path', 'creator:id,name', 'folder:id,name'])
            ->when($filters['q'] !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('title', 'like', '%'.$filters['q'].'%')
                ->orWhere('filename', 'like', '%'.$filters['q'].'%')))
            ->when($filters['format'] !== null, fn (Builder $q) => $q->where('format', $filters['format']))
            ->when($filters['autor'] === 'ia', fn (Builder $q) => $q->whereNotNull('agent_id'))
            ->when($filters['autor'] === 'pessoas', fn (Builder $q) => $q->whereNull('agent_id'))
            ->when($filters['pasta'] === 'sem', fn (Builder $q) => $q->whereNull('document_folder_id'))
            ->when($filters['pasta'] !== null && $filters['pasta'] !== 'sem', fn (Builder $q) => $q->where('document_folder_id', (int) $filters['pasta']));

        match ($filters['ordem']) {
            'antigos' => $query->oldest('id'),
            'nome' => $query->orderBy('title')->orderBy('id'),
            'tamanho' => $query->orderByDesc('size_bytes')->orderByDesc('id'),
            'tipo' => $query->orderBy('format')->latest('id'),
            default => $query->latest('id'),
        };

        // How many of the files this person sees are in each folder.
        $counts = $this->visible($user)->toBase()->selectRaw('document_folder_id, count(*) as total')->groupBy('document_folder_id')->pluck('total', 'document_folder_id');

        return Inertia::render('Documents/Index', [
            'documents' => $query->paginate(48)->withQueryString()->through(fn (GeneratedDocument $d) => $this->present($d)),
            'filters' => $filters,
            'folders' => DocumentFolder::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (DocumentFolder $folder) => ['id' => $folder->id, 'name' => $folder->name, 'count' => (int) ($counts[$folder->id] ?? 0)]),
            'total' => (int) $counts->sum(),
            'unfiled' => (int) ($counts[''] ?? 0),
            'formats' => DocumentFormat::options(),
            'templates' => DocumentTemplate::options(),
        ]);
    }

    public function storeFolder(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', TenantRule::unique('document_folders', 'name')]], [
            'name.unique' => 'Já existe uma pasta com esse nome.',
        ]);

        $folder = DocumentFolder::query()->create(['name' => trim($data['name']), 'created_by_user_id' => $user->id]);
        AuditLog::record($user, 'documents.folder_created', ['name' => $folder->name], subject: $folder);

        return to_route('documents.index', ['pasta' => $folder->id])->with('success', "Pasta «{$folder->name}» criada.");
    }

    public function updateFolder(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:120', TenantRule::unique('document_folders', 'name')->ignore($folder->id)]], [
            'name.unique' => 'Já existe uma pasta com esse nome.',
        ]);

        $old = $folder->name;
        $folder->update(['name' => trim($data['name'])]);
        AuditLog::record($user, 'documents.folder_renamed', ['from' => $old, 'to' => $folder->name], subject: $folder);

        return back()->with('success', 'Pasta renomeada.');
    }

    /**
     * Deleting a folder keeps its files: they go back to the top.
     */
    public function destroyFolder(Request $request, DocumentFolder $folder): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
        AuditLog::record($user, 'documents.folder_deleted', ['name' => $folder->name]);
        $folder->delete();

        return to_route('documents.index')->with('success', "Pasta «{$folder->name}» apagada. Os ficheiros ficaram em «Todos».");
    }

    /**
     * Into a folder (or out of every folder).
     */
    public function move(Request $request, GeneratedDocument $document): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments) && $this->canSee($user, $document), 403);
        $data = $request->validate(['folder_id' => ['nullable', 'integer', TenantRule::exists('document_folders')]]);

        $document->update(['document_folder_id' => $data['folder_id'] ?? null]);
        $folder = $document->folder()->value('name');

        return back()->with('success', $folder !== null ? "«{$document->title}» está agora em «{$folder}»." : "«{$document->title}» saiu da pasta.");
    }

    public function show(Request $request, GeneratedDocument $document): Response
    {
        $user = $this->user($request);
        abort_unless($this->canSee($user, $document), 403);
        $this->access->ensureDefaults();
        $domains = KnowledgeDomain::query()->orderBy('position')->get()->filter(fn (KnowledgeDomain $d) => $this->access->canOpen($user, $d))->values();

        return Inertia::render('Documents/Show', [
            'document' => [...$this->present($document->load(['agent:id,name,avatar_path', 'creator:id,name', 'knowledgeItem:id,title'])), 'source' => $document->source],
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
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
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
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
        abort_unless($this->canSee($user, $document), 403);
        $format = DocumentFormat::from($request->validate(['format' => ['required', Rule::enum(DocumentFormat::class)]])['format']);

        // A copy keeps the document type's style and images, while its skill exists.
        $skill = $document->skill_id !== null ? Skill::query()->find($document->skill_id) : null;
        $type = $skill !== null ? DocumentType::fromSkill($skill) : null;
        $copy = $this->make($user, $format, new DocumentSpec($document->title, $document->source, DocumentTemplate::tryFrom($document->template) ?? DocumentTemplate::Document, type: $type));

        return $copy === null ? back()->with('error', 'Não foi possível gerar o ficheiro.') : to_route('documents.show', $copy)->with('success', "Gerado em {$format->label()}.");
    }

    /**
     * A report drafted by an agent, exported as a file.
     */
    public function fromReport(Request $request, Report $report): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
        abort_unless(app(ReportController::class)->canSee($user, $report), 403);
        $format = DocumentFormat::from($request->validate(['format' => ['required', Rule::enum(DocumentFormat::class)]])['format']);

        $document = $this->make($user, $format, new DocumentSpec($report->title, $report->content, DocumentTemplate::Report));

        return $document === null ? back()->with('error', 'Não foi possível gerar o ficheiro.') : to_route('documents.show', $document)->with('success', "Exportado em {$format->label()}.");
    }

    public function file(Request $request, GeneratedDocument $document): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
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
        return GeneratedDocument::query()->when(! $user->hasPermission(Permission::ReadAllDocuments), fn (Builder $q) => $q->where(fn (Builder $w) => $w
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
            'agent' => $d->agent ? ['id' => $d->agent->id, 'name' => $d->agent->name, 'avatar_url' => $d->agent->avatarUrl()] : null,
            'creator' => $d->creator?->name,
            'knowledge_item' => $d->knowledge_item_id !== null ? ['id' => $d->knowledge_item_id] : null,
            'folder' => $d->folder ? ['id' => $d->folder->id, 'name' => $d->folder->name] : null,
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }
}
