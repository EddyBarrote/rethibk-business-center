<?php

namespace App\Http\Controllers;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeRepository;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\MessageAttachment;
use App\Tenancy\TenantRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * «Guardar no repositório» on a file of a conversation or of an email
 * (docs/DECISOES.md, 14.10.2026): whoever can open the file and write in the
 * knowledge base files a copy in a domain and folder. Answers in JSON, for
 * the file preview window.
 */
class KnowledgeImportController extends Controller
{
    public function __invoke(Request $request, KnowledgeRepository $repository, KnowledgeAccess $access): JsonResponse
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::WriteKnowledge), 403);
        $data = $request->validate([
            'source' => ['required', Rule::in(['attachment', 'email_attachment'])],
            'id' => ['required', 'integer'],
            'title' => ['nullable', 'string', 'max:255'],
            'domain_id' => ['required', 'integer', TenantRule::exists('knowledge_domains')],
            'folder_id' => ['nullable', 'integer', TenantRule::exists('knowledge_folders')],
        ]);

        $file = $data['source'] === 'attachment' ? MessageAttachment::query()->findOrFail($data['id']) : EmailAttachment::query()->findOrFail($data['id']);

        if ($file instanceof MessageAttachment) {
            abort_unless($file->task_id !== null && $file->task_message_id !== null, 404);
            Gate::authorize('view', $file->task);
        } else {
            abort_unless(EmailMessage::query()->visibleTo($user)->whereKey($file->email_message_id)->exists(), 403);
        }

        $domain = KnowledgeDomain::query()->findOrFail($data['domain_id']);
        abort_unless($access->canOpen($user, $domain), 403);
        $folder = filled($data['folder_id'] ?? null) ? KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->findOrFail($data['folder_id']) : null;

        $existing = $repository->duplicateOf($file, $user);

        if ($existing !== null) {
            return response()->json(['id' => $existing->id, 'title' => $existing->title, 'url' => "/knowledge/{$existing->id}", 'existed' => true]);
        }

        $item = $repository->fromStoredFile($file, $user, [
            'title' => $data['title'] ?? null,
            'knowledge_domain_id' => $domain->id,
            'knowledge_folder_id' => $folder?->id,
        ]);
        abort_if($item === null, 410, 'O ficheiro já não existe no armazenamento.');
        AuditLog::record($user, 'knowledge.imported', ['filename' => $file->fileName(), 'from' => $data['source']], subject: $item);

        return response()->json(['id' => $item->id, 'title' => $item->title, 'url' => "/knowledge/{$item->id}", 'existed' => false], 201);
    }

    /**
     * Where a person can file: the domains they open and their folders.
     */
    public function options(Request $request, KnowledgeAccess $access): JsonResponse
    {
        $user = $this->user($request);
        $access->ensureDefaults();
        $domains = KnowledgeDomain::query()->orderBy('position')->get()->filter(fn (KnowledgeDomain $d) => $access->canOpen($user, $d))->values();
        $folders = KnowledgeFolder::query()->whereIn('knowledge_domain_id', $domains->pluck('id'))->get();

        return response()->json([
            'can' => $user->hasPermission(Permission::WriteKnowledge),
            'domains' => $domains->map(fn (KnowledgeDomain $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'folders' => $folders->map(fn (KnowledgeFolder $f) => ['id' => $f->id, 'domain_id' => $f->knowledge_domain_id, 'path' => $f->path()])->sortBy('path')->values(),
        ]);
    }
}
