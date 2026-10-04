<?php

namespace App\Http\Controllers\Knowledge;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Folders inside a domain. Anyone who can open the domain creates them;
 * renaming and deleting is for the domain's curators. Deleting a folder
 * moves what was in it to the domain root, never deletes documents.
 */
class FolderController extends Controller
{
    public function __construct(private readonly KnowledgeAccess $access) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'domain_id' => ['required', 'integer', TenantRule::exists('knowledge_domains')],
            'parent_id' => ['nullable', 'integer', TenantRule::exists('knowledge_folders')],
        ]);
        $domain = KnowledgeDomain::query()->findOrFail($data['domain_id']);
        abort_unless($this->access->canOpen($user, $domain), 403);
        $parent = isset($data['parent_id']) ? KnowledgeFolder::query()->where('knowledge_domain_id', $domain->id)->findOrFail($data['parent_id']) : null;

        $folder = KnowledgeFolder::query()->create(['knowledge_domain_id' => $domain->id, 'parent_id' => $parent?->id, 'name' => $data['name']]);
        AuditLog::record($user, 'knowledge.folder_created', ['name' => $folder->name, 'domain' => $domain->name], subject: $folder);

        return to_route('knowledge.index', ['domain' => $domain->slug, 'folder' => $folder->id])->with('success', 'Pasta criada.');
    }

    public function update(Request $request, KnowledgeFolder $folder): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->access->canCurate($user, $folder->domain), 403);
        $folder->update($request->validate(['name' => ['required', 'string', 'max:120']]));

        return back()->with('success', 'Pasta renomeada.');
    }

    public function destroy(Request $request, KnowledgeFolder $folder): RedirectResponse
    {
        $user = $this->user($request);
        $domain = $folder->domain;
        abort_unless($this->access->canCurate($user, $domain), 403);

        // Everything below, at any depth, moves to the domain root.
        $ids = [$folder->id];

        for ($level = $ids, $depth = 0; $level !== [] && $depth < 10; $depth++) {
            $level = KnowledgeFolder::query()->whereIn('parent_id', $level)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        $domain->items()->whereIn('knowledge_folder_id', $ids)->update(['knowledge_folder_id' => null]);
        AuditLog::record($user, 'knowledge.folder_deleted', ['name' => $folder->name, 'domain' => $domain->name]);
        $parent = $folder->parent_id;
        $folder->delete();

        return to_route('knowledge.index', array_filter(['domain' => $domain->slug, 'folder' => $parent]))->with('success', 'Pasta apagada; o conteúdo passou para a raiz do domínio.');
    }
}
