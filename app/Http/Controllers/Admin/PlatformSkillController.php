<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Skills\SkillFiles;
use App\Models\PlatformSkill;
use App\Models\PlatformSkillFile;
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

    public function store(Request $request): RedirectResponse
    {
        $skill = PlatformSkill::query()->create($this->validated($request));

        return to_route('admin.skills.edit', $skill)->with('success', 'Skill global criada. As organizações já a podem activar.');
    }

    public function edit(PlatformSkill $skill): Response
    {
        return Inertia::render('Admin/Skills/Form', [
            'skill' => $skill->only(['id', 'key', 'name', 'description', 'instructions', 'is_active']),
            'files' => $skill->files->map(fn (PlatformSkillFile $file) => ['id' => $file->id, 'filename' => $file->filename, 'size' => $file->size, 'has_text' => $file->content !== null]),
        ]);
    }

    public function update(Request $request, PlatformSkill $skill): RedirectResponse
    {
        $skill->update($this->validated($request, $skill));

        return back()->with('success', 'Skill global guardada; as organizações que a usam vêem já a nova versão.');
    }

    public function destroy(PlatformSkill $skill, SkillFiles $files): RedirectResponse
    {
        $skill->files->each(fn (PlatformSkillFile $file) => $files->detach($file));
        $skill->delete();

        return to_route('admin.skills.index')->with('success', 'Skill global apagada em todas as organizações.');
    }

    public function storeFile(Request $request, PlatformSkill $skill, SkillFiles $files): RedirectResponse
    {
        $data = $request->validate(['file' => ['required', ...SkillFiles::RULES]]);
        $file = $files->attach($skill, $data['file']);

        return back()->with($file->content === null ? 'error' : 'success', $file->content === null ? "{$file->filename} guardado, mas sem texto legível." : "{$file->filename} juntado à skill.");
    }

    public function destroyFile(PlatformSkill $skill, PlatformSkillFile $file, SkillFiles $files): RedirectResponse
    {
        abort_unless($file->platform_skill_id === $skill->id, 404);
        $files->detach($file);

        return back()->with('success', 'Ficheiro removido.');
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
