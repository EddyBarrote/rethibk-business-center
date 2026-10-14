<?php

namespace App\Http\Controllers;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Knowledge\KnowledgeLocator;
use App\Ai\Knowledge\KnowledgeRepository;
use App\Attachments\AttachmentLimits;
use App\Attachments\AttachmentStore;
use App\Documents\MarkdownBlocks;
use App\Enums\KnowledgeType;
use App\Enums\Permission;
use App\Jobs\EmbedKnowledgeItem;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\MessageAttachment;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The knowledge base (docs/CONHECIMENTO.md): domains and folders, articles
 * written here, uploaded files, search, and review of what agents wrote.
 */
class KnowledgeController extends Controller
{
    public const UPLOAD_TYPES = 'pdf,docx,xlsx,pptx,txt,md,csv,html,png,jpg,jpeg';

    /** File kinds people filter the repository by, with their extensions. */
    public const KINDS = [
        'pdf' => ['pdf'],
        'office' => ['docx', 'xlsx', 'pptx'],
        'image' => ['png', 'jpg', 'jpeg', 'webp', 'gif', 'heic', 'heif'],
        'text' => ['txt', 'md', 'csv', 'html', 'htm', 'json', 'xml'],
    ];

    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly KnowledgeAccess $access,
        private readonly KnowledgeRepository $repository,
        private readonly KnowledgeLocator $locator,
        private readonly AttachmentStore $store,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $this->access->ensureDefaults();

        $domains = KnowledgeDomain::query()->orderBy('position')->orderBy('name')->get()
            ->filter(fn (KnowledgeDomain $d) => $this->access->canOpen($user, $d))->values();
        $domain = $domains->firstWhere('slug', $request->query('domain'));
        $folder = $domain !== null && $request->filled('folder')
            ? KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->find($request->integer('folder'))
            : null;
        $query = trim((string) $request->query('q', ''));
        $review = $request->query('view') === 'review';
        $files = $request->query('view') === 'files';
        $kind = array_key_exists((string) $request->query('kind'), self::KINDS) ? (string) $request->query('kind') : null;

        $visible = $this->access->items($user);
        $counts = (clone $visible)->where('status', KnowledgeItem::PUBLISHED)
            ->selectRaw('knowledge_domain_id, count(*) as total')->groupBy('knowledge_domain_id')->pluck('total', 'knowledge_domain_id');

        if ($query !== '') {
            $hits = $this->knowledge->search($query, 30, $user, $domain?->id);
            $items = $this->present($hits->pluck('item')->all(), $hits->pluck('excerpt')->all(), $hits->pluck('score')->all(), $hits->pluck('page')->all());
        } else {
            $list = (clone $visible)
                ->with(['domain:id,name,slug,color', 'folder'])
                ->when($review, fn (Builder $q) => $q->where('status', KnowledgeItem::PENDING_REVIEW))
                ->when($files, fn (Builder $q) => $q->whereNotNull('path'))
                ->when(! $review && ! $files && $domain !== null, fn (Builder $q) => $q->where('knowledge_domain_id', $domain?->id)->where('knowledge_folder_id', $folder?->id))
                ->when($kind !== null, fn (Builder $q) => $q->whereNotNull('path')->where(function (Builder $q) use ($kind) {
                    foreach (self::KINDS[(string) $kind] as $extension) {
                        $q->orWhere('filename', 'like', "%.{$extension}");
                    }
                }))
                ->latest('updated_at')
                ->limit($files || $kind !== null ? 500 : 100)
                ->get();
            $items = $this->present($list->all());
        }

        $folders = $domain === null ? collect() : KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->withCount(['children', 'items'])->orderBy('name')->get();

        return Inertia::render('Knowledge/Index', [
            'domains' => $domains->map(fn (KnowledgeDomain $d) => [
                ...$this->domain($d),
                'items' => (int) ($counts[$d->id] ?? 0),
            ])->values(),
            'domain' => $domain === null ? null : [...$this->domain($domain), 'can_curate' => $this->access->canCurate($user, $domain)],
            'folder' => $folder === null ? null : ['id' => $folder->id, 'name' => $folder->name, 'parent_id' => $folder->parent_id, 'path' => $folder->trail()],
            'folders' => $folders->where('parent_id', $folder?->id)->map(fn (KnowledgeFolder $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'items' => $f->items_count,
                'children' => $f->children_count,
            ])->values(),
            'items' => $items,
            'filters' => ['q' => $query, 'view' => $review ? 'review' : ($files ? 'files' : null), 'kind' => $kind],
            'pending' => (clone $visible)->where('status', KnowledgeItem::PENDING_REVIEW)->count(),
            'files' => (clone $visible)->whereNotNull('path')->count(),
            'upload' => [...AttachmentLimits::repository()->forScreen(), 'max_files' => (int) config('knowledge.max_upload_files', 200)],
            'unfiled' => (clone $visible)->whereNull('knowledge_domain_id')->count(),
            'semantic' => KnowledgeBase::embeddingsConfigured(),
            'accept' => '.'.str_replace(',', ',.', self::UPLOAD_TYPES),
            'can' => ['manage_domains' => $user->hasPermission(Permission::ManageKnowledge)],
        ]);
    }

    public function create(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::WriteKnowledge), 403);
        $this->access->ensureDefaults();

        return Inertia::render('Knowledge/Edit', [
            'item' => null,
            'defaults' => [
                'domain_id' => KnowledgeDomain::query()->where('slug', $request->query('domain'))->value('id'),
                'folder_id' => $request->integer('folder') ?: null,
            ],
            ...$this->editorOptions($user),
        ]);
    }

    public function edit(Request $request, KnowledgeItem $item): Response
    {
        $user = $this->user($request);
        abort_unless($this->canEdit($user, $item), 403);

        return Inertia::render('Knowledge/Edit', [
            'item' => [
                'id' => $item->id,
                'type' => $item->type->value,
                'title' => $item->title,
                'content' => $item->content,
                'is_file' => $item->isFile(),
                'domain_id' => $item->knowledge_domain_id,
                'folder_id' => $item->knowledge_folder_id,
            ],
            'defaults' => null,
            ...$this->editorOptions($user),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $this->validateArticle($request, $user);

        $item = $this->knowledge->remember(KnowledgeType::from($data['type']), $data['title'], $data['content'], $user, [
            'knowledge_domain_id' => $data['domain_id'],
            'knowledge_folder_id' => $data['folder_id'] ?? null,
        ]);
        AuditLog::record($user, 'knowledge.created', ['title' => $item->title], subject: $item);

        return to_route('knowledge.show', $item)->with('success', 'Artigo publicado.');
    }

    public function update(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->canEdit($user, $item), 403);
        $data = $this->validateArticle($request, $user, $item);
        $reindex = $data['title'] !== $item->title || (! $item->isFile() && $data['content'] !== $item->content);

        $item->update([
            'type' => $data['type'],
            'title' => $data['title'],
            'content' => $item->isFile() ? $item->content : $data['content'],
            'knowledge_domain_id' => $data['domain_id'],
            'knowledge_folder_id' => $data['folder_id'] ?? null,
        ]);

        if ($reindex) {
            $item->forceFill(['embedding_status' => 'pending'])->save();
            EmbedKnowledgeItem::dispatch($item->tenant_id, $item->id)->onQueue('embeddings');
        }

        AuditLog::record($user, 'knowledge.updated', ['title' => $item->title], subject: $item);

        return to_route('knowledge.show', $item)->with('success', 'Guardado.');
    }

    /**
     * Files into a domain and folder: sent in parts first (source
     * "repository" in /files), or, from older screens and tests, as one
     * form. A dragged folder sends each file's path inside it, and its
     * folders are created. A file already in the repository is not repeated.
     */
    public function upload(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::WriteKnowledge), 403);
        $data = $request->validate([
            'files' => ['required_without:uploads', 'array', 'max:20'],
            'files.*' => ['file', 'max:25600', 'mimes:'.self::UPLOAD_TYPES],
            'uploads' => ['required_without:files', 'array', 'max:'.(int) config('knowledge.max_upload_files', 200)],
            'uploads.*.id' => ['required', 'integer'],
            'uploads.*.path' => ['nullable', 'string', 'max:500'],
            'domain_id' => ['required', 'integer', TenantRule::exists('knowledge_domains')],
            'folder_id' => ['nullable', 'integer', TenantRule::exists('knowledge_folders')],
        ], ['files.*.mimes' => 'Formato não suportado. Use PDF, Word, Excel, PowerPoint, texto ou imagem.']);

        [$domain, $folder] = $this->placement($user, (int) $data['domain_id'], $data['folder_id'] ?? null);
        $saved = 0;
        $repeated = [];
        $place = fn (?string $relative) => $this->folderFor($domain, $folder, $relative);

        foreach ($request->file('files') ?? [] as $file) {
            $name = $file->getClientOriginalName();
            $existing = $this->repository->duplicateOf(hash_file('sha256', (string) $file->getRealPath()) ?: '', $user);

            if ($existing !== null) {
                $repeated[] = $existing->title;

                continue;
            }

            $this->fileUpload((string) $file->getRealPath(), $name, $file->getMimeType(), $user, $domain, $folder);
            $saved++;
        }

        /** @var array<int, string|null> $paths */
        $paths = [];

        foreach ((array) ($data['uploads'] ?? []) as $upload) {
            $paths[(int) $upload['id']] = $upload['path'] ?? null;
        }

        $staged = $paths === [] ? [] : $this->store->claimForRepository($user, array_keys($paths));

        foreach ($staged as $upload) {
            $local = Storage::disk($upload->disk)->path($upload->path);
            $existing = $this->repository->duplicateOf(hash_file('sha256', $local) ?: '', $user);

            if ($existing !== null) {
                $repeated[] = $existing->title;
            } else {
                $this->fileUpload($local, $upload->filename, $upload->mime_type, $user, $domain, $place($paths[$upload->id] ?? null));
                $saved++;
            }

            $this->store->delete($upload);
        }

        $message = match (true) {
            $saved === 0 => 'Nada carregado.',
            $saved === 1 => 'Ficheiro carregado. O texto é lido em segundo plano.',
            default => "{$saved} ficheiros carregados. O texto é lido em segundo plano.",
        };

        if ($repeated !== []) {
            $message .= ' '.(count($repeated) === 1 ? 'Um já existia' : count($repeated).' já existiam').' no repositório: '.Str::limit(implode(', ', array_unique($repeated)), 160).'.';
        }

        return back()->with($saved === 0 && $repeated !== [] ? 'error' : 'success', $message);
    }

    /**
     * A new version of a file, uploaded in parts; the current one goes to its history.
     */
    public function version(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($item->isFile() && $this->canEdit($user, $item), 403);
        $data = $request->validate(['upload_id' => ['required', 'integer']]);
        $upload = $this->store->claimForRepository($user, [(int) $data['upload_id']])->first();
        abort_unless($upload instanceof MessageAttachment, 422);

        $this->knowledge->replaceFile($item, Storage::disk($upload->disk)->path($upload->path), $upload->filename, $upload->mime_type, $user);
        $this->store->delete($upload);
        AuditLog::record($user, 'knowledge.versioned', ['title' => $item->title, 'version' => $item->version, 'filename' => $item->filename], subject: $item);

        return back()->with('success', "Versão {$item->version} carregada. O texto é lido em segundo plano.");
    }

    /**
     * An earlier version of a file.
     */
    public function versionFile(Request $request, KnowledgeItem $item, KnowledgeItemVersion $version): StreamedResponse
    {
        abort_unless($version->knowledge_item_id === $item->id && $this->access->canRead($this->user($request), $item), 404);
        $disk = Storage::disk($version->disk);
        abort_unless($disk->exists($version->path), 404);

        return $disk->download($version->path, $version->filename, ['Content-Type' => $version->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Read a file again: after a failure, or a scan read before there was a model.
     */
    public function reread(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($item->isFile() && $this->canEdit($user, $item), 403);
        $this->knowledge->reread($item);

        return back()->with('success', 'A ler outra vez, em segundo plano.');
    }

    /**
     * The picture of a file in the grid: the first page of a PDF, or the image itself.
     */
    public function thumbnail(Request $request, KnowledgeItem $item): StreamedResponse
    {
        abort_unless($item->isFile() && $this->access->canRead($this->user($request), $item), 404);
        $disk = Storage::disk($item->disk ?: KnowledgeBase::DISK);
        $path = $item->thumbnail_path ?? ($item->isImage() ? $item->path : null);
        abort_unless($path !== null && $disk->exists($path), 404);
        $mime = $item->thumbnail_path !== null ? 'image/png' : ($item->mime_type ?: 'image/jpeg');

        return $disk->response($path, null, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400'], 'inline');
    }

    public function show(Request $request, KnowledgeItem $item): Response
    {
        $user = $this->user($request);
        abort_unless($this->access->canRead($user, $item), 403);
        $item->load(['domain', 'folder', 'reviewer:id,name']);

        return Inertia::render('Knowledge/Show', [
            'item' => [
                ...$this->present([$item])[0],
                'content' => $item->content,
                'preview' => $this->previewKind($item),
                'reading_note' => $item->reading_note,
                'text_source' => $item->text_source,
                'page_sources' => $item->isPdf() ? $item->pageTexts()->where('source', 'model')->pluck('page') : [],
                'versions' => $item->versions()->get()->map(fn (KnowledgeItemVersion $v) => [
                    'id' => $v->id,
                    'version' => $v->version,
                    'filename' => $v->filename,
                    'size' => $v->size_bytes,
                    'created_at' => $v->created_at->toIso8601String(),
                ]),
                'reviewed_by' => $item->reviewer?->name,
                'reviewed_at' => $item->reviewed_at?->toIso8601String(),
                'folder_trail' => $item->folder ? $item->folder->trail() : [],
            ],
            'page' => $request->integer('page') ?: null,
            'upload' => AttachmentLimits::repository()->forScreen(),
            'can' => [
                'edit' => $this->canEdit($user, $item),
                'review' => $item->status !== KnowledgeItem::PUBLISHED && $this->access->canCurate($user, $item->domain),
                'delete' => $this->canEdit($user, $item),
            ],
        ]);
    }

    public function review(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->access->canRead($user, $item) && $this->access->canCurate($user, $item->domain), 403);
        $decision = $request->validate(['decision' => ['required', Rule::in(['approve', 'reject'])]])['decision'];

        $item->forceFill([
            'status' => $decision === 'approve' ? KnowledgeItem::PUBLISHED : KnowledgeItem::REJECTED,
            'reviewed_by_user_id' => $user->id,
            'reviewed_at' => now(),
        ])->save();
        AuditLog::record($user, "knowledge.{$decision}d", ['title' => $item->title], subject: $item);

        return back()->with('success', $decision === 'approve' ? 'Aprovado: os agentes já o vêem.' : 'Rejeitado.');
    }

    public function destroy(Request $request, KnowledgeItem $item): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->canEdit($user, $item), 403);
        AuditLog::record($user, 'knowledge.deleted', ['title' => $item->title, 'filename' => $item->filename], subject: $item);
        $domain = $item->domain?->slug;
        $folder = $item->knowledge_folder_id;
        $this->knowledge->forget($item);

        return to_route('knowledge.index', array_filter(['domain' => $domain, 'folder' => $folder]))->with('success', 'Apagado.');
    }

    /**
     * The original file: inline for the preview (PDF, images), as an
     * attachment otherwise.
     */
    public function file(Request $request, KnowledgeItem $item): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($item->isFile() && $this->access->canRead($user, $item), 404);
        $disk = Storage::disk($item->disk ?: KnowledgeBase::DISK);
        abort_unless($disk->exists((string) $item->path), 404);

        $inline = $request->boolean('inline') && in_array($this->previewKind($item), ['pdf', 'image'], true);
        $headers = ['Content-Type' => $item->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'];

        return $inline
            ? $disk->response((string) $item->path, $item->filename, $headers, 'inline')
            : $disk->download((string) $item->path, $item->filename, $headers);
    }

    /**
     * @return array<string, mixed>
     */
    private function editorOptions(User $user): array
    {
        $domains = KnowledgeDomain::query()->orderBy('position')->get()->filter(fn (KnowledgeDomain $d) => $this->access->canOpen($user, $d))->values();
        $folders = KnowledgeFolder::query()->whereIn('knowledge_domain_id', $domains->pluck('id'))->get();

        return [
            'domains' => $domains->map(fn (KnowledgeDomain $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'folders' => $folders->map(fn (KnowledgeFolder $f) => ['id' => $f->id, 'domain_id' => $f->knowledge_domain_id, 'path' => $f->path()])->sortBy('path')->values(),
            'types' => array_values(array_filter(KnowledgeType::options(), fn ($o) => $o['value'] !== 'document')),
        ];
    }

    /**
     * @return array{type: string, title: string, content: string, domain_id: int, folder_id?: int|null}
     */
    private function validateArticle(Request $request, User $user, ?KnowledgeItem $item = null): array
    {
        abort_unless($user->hasPermission(Permission::WriteKnowledge), 403);
        $data = $request->validate([
            'type' => ['required', Rule::enum(KnowledgeType::class)],
            'title' => ['required', 'string', 'max:255'],
            'content' => [$item?->isFile() ? 'nullable' : 'required', 'nullable', 'string', 'max:200000'],
            'domain_id' => ['required', 'integer', TenantRule::exists('knowledge_domains')],
            'folder_id' => ['nullable', 'integer', TenantRule::exists('knowledge_folders')],
        ]);

        [$domain, $folder] = $this->placement($user, (int) $data['domain_id'], $data['folder_id'] ?? null);

        return [...$data, 'content' => (string) ($data['content'] ?? ''), 'domain_id' => $domain->id, 'folder_id' => $folder?->id];
    }

    /**
     * The domain and folder someone files into, checking they may open it.
     *
     * @return array{0: KnowledgeDomain, 1: KnowledgeFolder|null}
     */
    private function placement(User $user, int $domainId, mixed $folderId): array
    {
        $domain = KnowledgeDomain::query()->findOrFail($domainId);
        abort_unless($this->access->canOpen($user, $domain), 403);
        $folder = $folderId ? KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->find((int) $folderId) : null;

        if ($folderId && $folder === null) {
            abort(422, 'A pasta não pertence a este domínio.');
        }

        return [$domain, $folder];
    }

    private function fileUpload(string $local, string $name, ?string $mime, User $user, KnowledgeDomain $domain, ?KnowledgeFolder $folder): KnowledgeItem
    {
        $item = $this->knowledge->rememberFile($local, $name, $mime, pathinfo($name, PATHINFO_FILENAME) ?: $name, $user, [
            'knowledge_domain_id' => $domain->id,
            'knowledge_folder_id' => $folder?->id,
        ]);
        AuditLog::record($user, 'knowledge.uploaded', ['filename' => $name], subject: $item);

        return $item;
    }

    /**
     * The folder a file of a dragged folder goes to: its path inside the
     * dragged folder, under the folder being browsed, created as needed.
     */
    private function folderFor(KnowledgeDomain $domain, ?KnowledgeFolder $base, ?string $relative): ?KnowledgeFolder
    {
        $dirs = trim(str_replace('\\', '/', dirname(trim((string) $relative, '/'))), './');

        if ($dirs === '') {
            return $base;
        }

        $prefix = $base === null ? '' : collect($base->trail())->pluck('name')->implode('/').'/';

        return $this->locator->folder($domain, $prefix.$dirs, create: true) ?? $base;
    }

    private function canEdit(User $user, KnowledgeItem $item): bool
    {
        if (! $this->access->canRead($user, $item)) {
            return false;
        }

        $mine = $item->created_by_type === $user->getMorphClass() && $item->created_by_id === $user->id;

        return $mine || $this->access->canCurate($user, $item->domain);
    }

    private function previewKind(KnowledgeItem $item): string
    {
        if (! $item->isFile()) {
            return 'markdown';
        }

        $extension = Str::lower(pathinfo((string) $item->filename, PATHINFO_EXTENSION));

        return match (true) {
            $extension === 'pdf' => 'pdf',
            in_array($extension, ['png', 'jpg', 'jpeg'], true) => 'image',
            in_array($extension, ['md'], true) || $item->source_type === (new GeneratedDocument)->getMorphClass() => 'markdown',
            default => 'text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function domain(KnowledgeDomain $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'slug' => $d->slug,
            'description' => $d->description,
            'color' => $d->color,
            'restricted' => $d->isRestricted(),
        ];
    }

    /**
     * One line of plain text out of Markdown, for list excerpts.
     */
    private static function plain(string $markdown): string
    {
        $text = MarkdownBlocks::inline((string) preg_replace(['/^\s{0,3}(#{1,6}|[-*+]|\d+[.)]|>)\s+/m', '/^\s*\|?[\s:|-]+\|?\s*$/m', '/\|/'], ['', '', ' '], mb_substr($markdown, 0, 1200)));

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Rows for lists, with the author's name resolved in two queries.
     *
     * @param  list<KnowledgeItem>  $items
     * @param  list<string>  $excerpts
     * @param  list<float>  $scores
     * @param  list<int|null>  $pages
     * @return list<array<string, mixed>>
     */
    private function present(array $items, array $excerpts = [], array $scores = [], array $pages = []): array
    {
        $collection = new Collection($items);
        $collection->loadMissing(['domain:id,name,slug,color', 'folder']);
        $agentClass = (new Agent)->getMorphClass();
        $userClass = (new User)->getMorphClass();
        $agents = Agent::query()->whereIn('id', $collection->where('created_by_type', $agentClass)->pluck('created_by_id'))->pluck('name', 'id');
        $users = User::query()->whereIn('id', $collection->where('created_by_type', $userClass)->pluck('created_by_id'))->pluck('name', 'id');

        return array_map(function (KnowledgeItem $item, int $index) use ($excerpts, $scores, $pages, $agentClass, $userClass, $agents, $users) {
            $author = match ($item->created_by_type) {
                $agentClass => ['kind' => 'agent', 'name' => $agents[$item->created_by_id] ?? 'Agente', 'id' => $item->created_by_id],
                $userClass => ['kind' => 'user', 'name' => $users[$item->created_by_id] ?? 'Pessoa', 'id' => $item->created_by_id],
                default => ['kind' => 'system', 'name' => 'Sistema', 'id' => null],
            };

            return [
                'id' => $item->id,
                'type' => $item->type->value,
                'type_label' => $item->type->label(),
                'title' => $item->title,
                'excerpt' => Str::limit(self::plain($excerpts[$index] ?? $item->content), 220),
                'score' => $scores[$index] ?? null,
                'status' => $item->status,
                'is_external' => $item->is_external,
                'embedding_status' => $item->embedding_status,
                'domain' => $item->domain ? ['name' => $item->domain->name, 'slug' => $item->domain->slug, 'color' => $item->domain->color] : null,
                'folder' => $item->folder ? ['id' => $item->folder->id, 'name' => $item->folder->name] : null,
                'file' => $item->isFile() ? [
                    'name' => $item->filename,
                    'size' => $item->size_bytes,
                    'extension' => Str::lower(pathinfo((string) $item->filename, PATHINFO_EXTENSION)),
                    'pages' => $item->pages,
                    'version' => $item->version,
                    'reading' => $item->reading_status,
                    'text_source' => $item->text_source,
                    'thumbnail' => $item->thumbnail_path !== null || $item->isImage() ? "/knowledge/{$item->id}/thumbnail?v={$item->version}" : null,
                ] : null,
                'page' => $pages[$index] ?? null,
                'author' => $author,
                'created_at' => $item->created_at->toIso8601String(),
                'updated_at' => $item->updated_at->toIso8601String(),
            ];
        }, $items, array_keys($items));
    }
}
