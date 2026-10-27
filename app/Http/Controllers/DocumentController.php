<?php

namespace App\Http\Controllers;

use App\Ai\Capabilities\Local\DraftReport;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Documents\DocumentFormat;
use App\Documents\DocumentGenerator;
use App\Documents\DocumentSpec;
use App\Documents\DocumentTemplate;
use App\Documents\DocumentType;
use App\Enums\KnowledgeType;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\DocumentFolder;
use App\Models\GeneratedDocument;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\Report;
use App\Models\Skill;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Files generated from Markdown (Word, PowerPoint, Excel, PDF): by agents
 * with documents.generate, by people here, or from a report.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentGenerator $generator,
        private readonly KnowledgeAccess $access,
        private readonly ReportController $reports,
        private readonly KnowledgeBase $knowledge,
    ) {}

    /** The «format» of a report in the list, as the address writes it. */
    private const REPORT = 'relatorio';

    /** The «ordem» of the list, as the address writes it. */
    private const SORTS = ['recentes', 'antigos', 'nome', 'tamanho', 'tipo'];

    /**
     * Documentos (docs/DECISOES.md, "Gestor de ficheiros" and "Documentos:
     * relatórios e ficheiros juntos"): the files and the reports agents
     * drafted, in one list with folders, search, author (an agent or a
     * person), type and order, all in the address so a view can be shared.
     */
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $formats = [...array_column(DocumentFormat::cases(), 'value'), self::REPORT];
        $pasta = (string) $request->query('pasta');
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'format' => in_array($request->query('format'), $formats, true) ? (string) $request->query('format') : null,
            'autor' => in_array($request->query('autor'), ['ia', 'pessoas'], true) ? (string) $request->query('autor') : null,
            'pasta' => in_array($pasta, ['sem', 'rever'], true) || ctype_digit($pasta) ? $pasta : null,
            'ordem' => in_array($request->query('ordem'), self::SORTS, true) ? (string) $request->query('ordem') : 'recentes',
        ];
        $folder = ctype_digit((string) $filters['pasta']) ? (int) $filters['pasta'] : null;

        // Reports are written by agents and are never files of one format; «Por rever» holds only reports.
        $withFiles = $filters['format'] !== self::REPORT && $filters['pasta'] !== 'rever';
        $withReports = in_array($filters['format'], [null, self::REPORT], true) && $filters['autor'] !== 'pessoas';

        $files = $this->visible($user)->toBase()
            ->selectRaw("'file' as kind, id, title, format, size_bytes as size, created_at")
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('title', 'like', '%'.$filters['q'].'%')
                ->orWhere('filename', 'like', '%'.$filters['q'].'%')))
            ->when($filters['format'] !== null, fn ($q) => $q->where('format', $filters['format']))
            ->when($filters['autor'] === 'ia', fn ($q) => $q->whereNotNull('agent_id'))
            ->when($filters['autor'] === 'pessoas', fn ($q) => $q->whereNull('agent_id'))
            ->when($filters['pasta'] === 'sem', fn ($q) => $q->whereNull('document_folder_id'))
            ->when($folder !== null, fn ($q) => $q->where('document_folder_id', $folder));
        $reports = $this->reports->visible($user)->toBase()
            ->selectRaw("'report' as kind, id, title, '".self::REPORT."' as format, length(content) as size, created_at")
            ->when($filters['q'] !== '', fn ($q) => $q->where('title', 'like', '%'.$filters['q'].'%'))
            ->when($filters['pasta'] === 'sem', fn ($q) => $q->whereNull('document_folder_id'))
            ->when($filters['pasta'] === 'rever', fn ($q) => $q->where('status', 'draft'))
            ->when($folder !== null, fn ($q) => $q->where('document_folder_id', $folder));

        $union = match (true) {
            $withFiles && $withReports => $files->unionAll($reports),
            $withReports => $reports,
            default => $files,
        };
        $query = DB::query()->fromSub($union, 'items');
        match ($filters['ordem']) {
            'antigos' => $query->orderBy('created_at')->orderBy('id'),
            'nome' => $query->orderBy('title')->orderBy('id'),
            'tamanho' => $query->orderByDesc('size')->orderByDesc('id'),
            'tipo' => $query->orderBy('format')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
        $page = $query->paginate(48)->withQueryString();

        // The rows of the page, read in full: one query for the files and one for the reports.
        $rows = collect($page->items());
        $fileModels = GeneratedDocument::query()->with(['agent:id,name,avatar_path', 'creator:id,name', 'folder:id,name'])
            ->whereKey($rows->where('kind', 'file')->pluck('id'))->get()->keyBy('id');
        $reportModels = Report::query()->with(['agent:id,name,avatar_path', 'folder:id,name'])
            ->whereKey($rows->where('kind', 'report')->pluck('id'))->get()->keyBy('id');
        $page->setCollection($rows->map(fn (object $row) => $row->kind === 'file'
            ? $this->present($fileModels[$row->id])
            : $this->presentReport($reportModels[$row->id]))->values());

        // How many documents this person sees are in each folder.
        $counts = $this->visible($user)->toBase()->selectRaw('document_folder_id, count(*) as total')->groupBy('document_folder_id')->pluck('total', 'document_folder_id');
        $reportCounts = $this->reports->visible($user)->toBase()->selectRaw('document_folder_id, count(*) as total')->groupBy('document_folder_id')->pluck('total', 'document_folder_id');
        $count = fn (int|string $key): int => (int) ($counts[$key] ?? 0) + (int) ($reportCounts[$key] ?? 0);

        return Inertia::render('Documents/Index', [
            'documents' => $page,
            'filters' => $filters,
            'folders' => DocumentFolder::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (DocumentFolder $f) => ['id' => $f->id, 'name' => $f->name, 'count' => $count($f->id)]),
            'total' => (int) $counts->sum() + (int) $reportCounts->sum(),
            'unfiled' => $count(''),
            'to_review' => $this->reports->visible($user)->where('status', 'draft')->count(),
            'formats' => DocumentFormat::options(),
            'types' => [...DocumentFormat::options(), ['value' => self::REPORT, 'label' => 'Relatório']],
            'templates' => DocumentTemplate::options(),
            // Where the chosen documents can be archived, loaded only when asked for.
            'knowledge' => Inertia::optional(fn () => $this->knowledgeTargets($user)),
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

    /**
     * Many documents into a folder (or out of every folder) at once. Those the
     * person cannot see are left alone.
     */
    public function bulkMove(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
        $data = $request->validate([...$this->bulkRules(), 'folder_id' => ['nullable', 'integer', TenantRule::exists('document_folders')]]);
        [$files, $reports] = $this->chosen($user, $data);

        $into = ['document_folder_id' => $data['folder_id'] ?? null];
        $moved = ($files->isEmpty() ? 0 : GeneratedDocument::query()->whereKey($files->modelKeys())->update($into))
            + ($reports->isEmpty() ? 0 : Report::query()->whereKey($reports->modelKeys())->update($into));
        $folder = isset($data['folder_id']) ? DocumentFolder::query()->whereKey($data['folder_id'])->value('name') : null;
        $documents = $this->documents($moved);

        return back()->with('success', $folder !== null ? "{$documents} em «{$folder}»." : "{$documents} fora das pastas.");
    }

    /**
     * Many documents in one ZIP: the files as they are, a report as its
     * Markdown text; two with the same name get a number.
     */
    public function bulkDownload(Request $request): BinaryFileResponse
    {
        $user = $this->user($request);
        [$files, $reports] = $this->chosen($user, $request->validate($this->bulkRules()));
        abort_if($files->isEmpty() && $reports->isEmpty(), 404);

        $entries = $files->map(function (GeneratedDocument $document) {
            $disk = Storage::disk($document->disk);

            return $disk->exists($document->path) ? [$document->filename, (string) $disk->get($document->path)] : null;
        })->filter()->concat($reports->map(fn (Report $report) => [(Str::slug(Str::limit($report->title, 80, '')) ?: 'relatorio').'.md', $report->content]));

        $path = (string) tempnam(sys_get_temp_dir(), 'documentos');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $names = [];
        foreach ($entries as [$filename, $content]) {
            $name = $filename;
            for ($n = 2; isset($names[$name]); $n++) {
                $name = pathinfo($filename, PATHINFO_FILENAME)."-{$n}.".pathinfo($filename, PATHINFO_EXTENSION);
            }
            $names[$name] = true;
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return response()->download($path, 'documentos-'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }

    /**
     * Many documents into the knowledge base; those already there are skipped.
     */
    public function bulkFile(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::CreateDocuments), 403);
        $data = $request->validate([
            ...$this->bulkRules(),
            'domain_id' => ['required', 'integer', TenantRule::exists('knowledge_domains')],
            'folder_id' => ['nullable', 'integer', TenantRule::exists('knowledge_folders')],
        ]);
        $domain = KnowledgeDomain::query()->findOrFail($data['domain_id']);
        abort_unless($this->access->canOpen($user, $domain), 403);
        $folder = isset($data['folder_id']) ? KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->findOrFail($data['folder_id']) : null;
        $place = ['knowledge_domain_id' => $domain->id, 'knowledge_folder_id' => $folder?->id];
        [$files, $reports] = $this->chosen($user, $data);

        $filed = 0;
        foreach ($files->whereNull('knowledge_item_id') as $document) {
            $item = $this->generator->fileInKnowledge($document, $user, $place);
            AuditLog::record($user, 'documents.filed', ['title' => $document->title, 'domain' => $domain->name], subject: $item);
            $filed++;
        }
        $archived = KnowledgeItem::query()->where('source_type', (new Report)->getMorphClass())->whereIn('source_id', $reports->modelKeys())->pluck('source_id')->all();
        foreach ($reports->reject(fn (Report $report) => in_array($report->id, $archived)) as $report) {
            $item = $this->knowledge->remember(KnowledgeType::Document, $report->title, $report->content, $user, [...$place, 'source_type' => $report->getMorphClass(), 'source_id' => $report->id]);
            AuditLog::record($user, 'documents.filed', ['title' => $report->title, 'domain' => $domain->name], subject: $item);
            $filed++;
        }

        return back()->with('success', $filed === 0 ? 'Já estavam todos na base de conhecimento.' : "{$this->documents($filed)} ".($filed === 1 ? 'arquivado' : 'arquivados')." em {$domain->name}.");
    }

    /**
     * Deletes the chosen documents this person may delete (their own files; a
     * manager, any file and the reports); the others stay and the message
     * says how many.
     */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        [$files, $reports] = $this->chosen($user, $request->validate($this->bulkRules()));
        $allowed = $files->filter(fn (GeneratedDocument $d) => $user->isManager() || $d->created_by_user_id === $user->id);
        $allowedReports = $user->isManager() ? $reports : new EloquentCollection;

        foreach ($allowed as $document) {
            AuditLog::record($user, 'documents.deleted', ['title' => $document->title, 'format' => $document->format]);
            Storage::disk($document->disk)->delete($document->path);
            $document->delete();
        }
        foreach ($allowedReports as $report) {
            AuditLog::record($user, 'report.deleted', ['title' => $report->title, 'type' => $report->type]);
            $report->delete();
        }

        $deleted = $allowed->count() + $allowedReports->count();
        $kept = $files->count() + $reports->count() - $deleted;
        $message = match ($deleted) {
            0 => 'Nenhum documento apagado.',
            1 => '1 documento apagado.',
            default => "{$deleted} documentos apagados.",
        };
        if ($kept > 0) {
            $message .= $kept === 1 ? ' 1 documento não pode ser apagado por si e ficou.' : " {$kept} documentos não podem ser apagados por si e ficaram.";
        }

        return back()->with($deleted === 0 ? 'error' : 'success', $message);
    }

    public function show(Request $request, GeneratedDocument $document): Response
    {
        $user = $this->user($request);
        abort_unless($this->canSee($user, $document), 403);
        ['domains' => $domains, 'folders' => $folders] = $this->knowledgeTargets($user);

        return Inertia::render('Documents/Show', [
            'document' => [
                ...$this->present($document->load(['agent:id,name,avatar_path', 'creator:id,name', 'knowledgeItem:id,title', 'report:id,title'])),
                'source' => $document->source,
                'report' => $document->report ? ['id' => $document->report->id, 'title' => $document->report->title] : null,
            ],
            'formats' => DocumentFormat::options(),
            'domains' => $domains,
            'folders' => $folders,
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
        $document?->update(['report_id' => $report->id, 'document_folder_id' => $report->document_folder_id]);

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

    /**
     * @return array<string, mixed>
     */
    private function bulkRules(): array
    {
        return [
            'ids' => ['array', 'max:200', 'required_without:report_ids'], 'ids.*' => ['integer'],
            'report_ids' => ['array', 'max:200', 'required_without:ids'], 'report_ids.*' => ['integer'],
        ];
    }

    /**
     * The chosen files and reports that this person sees.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: EloquentCollection<int, GeneratedDocument>, 1: EloquentCollection<int, Report>}
     */
    private function chosen(User $user, array $data): array
    {
        return [
            $this->visible($user)->whereKey($data['ids'] ?? [])->get(),
            $this->reports->visible($user)->whereKey($data['report_ids'] ?? [])->get(),
        ];
    }

    /**
     * The knowledge domains this person can open, and their folders.
     *
     * @return array{domains: Collection<int, array{id: int, name: string}>, folders: Collection<int, array{id: int, domain_id: int, path: string}>}
     */
    private function knowledgeTargets(User $user): array
    {
        $this->access->ensureDefaults();
        $domains = KnowledgeDomain::query()->orderBy('position')->get()->filter(fn (KnowledgeDomain $d) => $this->access->canOpen($user, $d))->values();

        return [
            'domains' => $domains->map(fn (KnowledgeDomain $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'folders' => KnowledgeFolder::query()->whereIn('knowledge_domain_id', $domains->pluck('id'))->get()
                ->map(fn (KnowledgeFolder $f) => ['id' => $f->id, 'domain_id' => $f->knowledge_domain_id, 'path' => $f->path()])->sortBy('path')->values(),
        ];
    }

    private function documents(int $count): string
    {
        return $count === 1 ? '1 documento' : "{$count} documentos";
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
            'kind' => 'file',
            'key' => "file-{$d->id}",
            'href' => "/documents/{$d->id}",
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
            'status' => null,
            'created_at' => $d->created_at->toIso8601String(),
        ];
    }

    /**
     * A report in the list of Documentos, in the shape of a file.
     *
     * @return array<string, mixed>
     */
    private function presentReport(Report $r): array
    {
        $type = DraftReport::TYPES[$r->type] ?? $r->type;

        return [
            'id' => $r->id,
            'kind' => 'report',
            'key' => "report-{$r->id}",
            'href' => "/reports/{$r->id}",
            'title' => $r->title,
            'format' => self::REPORT,
            'format_label' => 'Relatório',
            'template' => $r->type,
            'template_label' => $type,
            'filename' => $type,
            'size' => null,
            'agent' => $r->agent ? ['id' => $r->agent->id, 'name' => $r->agent->name, 'avatar_url' => $r->agent->avatarUrl()] : null,
            'creator' => null,
            'knowledge_item' => null,
            'folder' => $r->folder ? ['id' => $r->folder->id, 'name' => $r->folder->name] : null,
            'status' => $r->status,
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
