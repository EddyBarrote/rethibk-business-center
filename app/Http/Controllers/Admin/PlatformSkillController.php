<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Skills\SkillFiles;
use App\Ai\Skills\SkillVersions;
use App\Models\PlatformSkill;
use App\Models\PlatformSkillFile;
use App\Models\PlatformSkillVersion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global skills (docs/CAPACIDADES.md): written once by Rethink, offered to
 * every tenant, activated by each company that wants them.
 */
class PlatformSkillController extends AdminController
{
    public function index(): Response
    {
        return Inertia::render('Admin/Skills/Index', [
            'skills' => PlatformSkill::query()->withCount('files')->orderBy('name')->get()->map(fn (PlatformSkill $skill) => [
                ...$skill->only(['id', 'key', 'name', 'description', 'is_active']),
                'files' => $skill->files_count,
                'updated_at' => $skill->updated_at?->toIso8601String(),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Skills/Form', ['skill' => null, 'files' => []]);
    }

    public function store(Request $request, SkillVersions $versions): RedirectResponse
    {
        $skill = PlatformSkill::query()->create($this->validated($request));
        $versions->record($skill, $this->admin($request));

        return to_route('admin.skills.edit', $skill)->with('success', 'Skill global criada. As organizações já a podem activar.');
    }

    public function edit(PlatformSkill $skill): Response
    {
        return Inertia::render('Admin/Skills/Form', [
            'skill' => $skill->only(['id', 'key', 'name', 'description', 'instructions', 'is_active']),
            'files' => $skill->files->map(fn (PlatformSkillFile $file) => ['id' => $file->id, 'filename' => $file->filename, 'size' => $file->size, 'has_text' => $file->content !== null]),
            'versions' => $this->versionRows($skill),
        ]);
    }

    public function update(Request $request, PlatformSkill $skill, SkillVersions $versions): RedirectResponse
    {
        $data = $this->validated($request, $skill);
        $versions->baseline($skill);
        $skill->update($data);
        $version = $versions->record($skill, $this->admin($request));

        return back()->with('success', $version !== null
            ? "Skill global guardada (versão {$version->number}); as organizações que a usam vêem já a nova versão."
            : 'Skill global guardada.');
    }

    public function destroy(PlatformSkill $skill, SkillFiles $files): RedirectResponse
    {
        // The skill and its history go together, with every stored file.
        $files->purge($skill);
        $skill->delete();

        return to_route('admin.skills.index')->with('success', 'Skill global apagada em todas as organizações.');
    }

    public function storeFile(Request $request, PlatformSkill $skill, SkillFiles $files, SkillVersions $versions): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', ...SkillFiles::RULES]]);
        $versions->baseline($skill);
        $file = $files->attach($skill, $data['file']);
        $versions->record($skill, $this->admin($request));

        return back()->with($file->content === null ? 'error' : 'success', $file->content === null ? "{$file->filename} guardado, mas sem texto legível." : "{$file->filename} juntado à skill.");
    }

    public function destroyFile(Request $request, PlatformSkill $skill, PlatformSkillFile $file, SkillFiles $files, SkillVersions $versions): RedirectResponse
    {
        abort_unless($file->platform_skill_id === $skill->id, 404);
        $versions->baseline($skill);
        $files->detach($file);
        $versions->record($skill, $this->admin($request));

        return back()->with('success', 'Ficheiro removido.');
    }

    /**
     * One version of a global skill and what changed from it to now.
     */
    public function version(PlatformSkill $skill, int $number, SkillVersions $versions): Response
    {
        $version = $skill->versions()->with('author:id,name')->where('number', $number)->firstOrFail();

        return Inertia::render('Admin/Skills/Version', [
            'skill' => $skill->only(['id', 'key', 'name']),
            'version' => $this->versionDetail($version, (int) $skill->versions()->reorder()->max('number')),
            'diff' => $versions->compare($skill, $version),
        ]);
    }

    /**
     * «Repor esta versão» for every organisation that uses the skill: it
     * comes back as a new version; the history keeps everything.
     */
    public function restoreVersion(Request $request, PlatformSkill $skill, int $number, SkillVersions $versions): RedirectResponse
    {
        $version = $skill->versions()->where('number', $number)->firstOrFail();
        $restored = $versions->restore($skill, $version, $this->admin($request));

        return to_route('admin.skills.edit', $skill)->with('success', "Versão {$version->number} reposta: é agora a versão {$restored->number}, em todas as organizações que a usam.");
    }

    /**
     * @return list<array{number: int, author: string|null, created_at: string|null, restored_from: int|null, is_current: bool}>
     */
    private function versionRows(PlatformSkill $skill): array
    {
        $rows = $skill->versions()->with('author:id,name')->limit(100)->get(['id', 'platform_skill_id', 'number', 'restored_from', 'created_by_admin_id', 'created_at']);
        $latest = (int) $rows->max('number');

        return $rows->map(fn (PlatformSkillVersion $version) => [
            'number' => $version->number,
            'author' => $version->author?->name,
            'created_at' => $version->created_at?->toIso8601String(),
            'restored_from' => $version->restored_from,
            'is_current' => $version->number === $latest,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function versionDetail(PlatformSkillVersion $version, int $latest): array
    {
        return [
            'number' => $version->number,
            'author' => $version->author?->name,
            'created_at' => $version->created_at?->toIso8601String(),
            'restored_from' => $version->restored_from,
            'is_current' => $version->number === $latest,
            'key' => $version->key,
            ...collect($version->content())->except('files')->all(),
            'files' => collect($version->files ?? [])->map(fn (array $file) => ['filename' => $file['filename'], 'size' => (int) $file['size'], 'has_text' => $file['content'] !== null])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?PlatformSkill $skill = null): array
    {
        return $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', Rule::unique('platform_skills', 'key')->ignore($skill)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'instructions' => ['required', 'string', 'max:60000'],
            'is_active' => ['boolean'],
        ]);
    }
}
