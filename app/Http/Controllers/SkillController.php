<?php

namespace App\Http\Controllers;

use App\Ai\Skills\SkillFiles;
use App\Models\AuditLog;
use App\Models\PlatformSkill;
use App\Models\Skill;
use App\Models\SkillFile;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company's skills (docs/CAPACIDADES.md): its own instruction packages,
 * and the global ones the super admin offers, which it activates.
 */
class SkillController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('manage-catalog');

        $skills = Skill::query()->with('platformSkill')->withCount(['agents', 'files'])->get();
        $activated = $skills->whereNotNull('platform_skill_id')->keyBy('platform_skill_id');

        return Inertia::render('Skills/Index', [
            'skills' => $skills->whereNull('platform_skill_id')->sortBy('name')->values()->map(fn (Skill $skill) => [
                'id' => $skill->id,
                'key' => $skill->key,
                'name' => $skill->displayName(),
                'description' => $skill->displayDescription(),
                'is_enabled' => $skill->is_enabled,
                'agents' => $skill->agents_count,
                'files' => $skill->files_count,
                'updated_at' => $skill->updated_at?->toIso8601String(),
            ]),
            'globalSkills' => PlatformSkill::query()->where('is_active', true)->withCount('files')->orderBy('name')->get()->map(fn (PlatformSkill $skill) => [
                'id' => $skill->id,
                'key' => $skill->key,
                'name' => $skill->name,
                'description' => $skill->description,
                'files' => $skill->files_count,
                'activated' => (bool) $activated->get($skill->id)?->is_enabled,
                'agents' => (int) $activated->get($skill->id)?->agents_count,
                'skill_id' => $activated->get($skill->id)?->id,
            ]),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('manage-catalog');

        return Inertia::render('Skills/Form', ['skill' => null, 'files' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $skill = new Skill($this->validated($request));
        $skill->forceFill(['created_by_user_id' => $this->user($request)->id])->save();
        AuditLog::record($this->user($request), 'skill.created', ['key' => $skill->key], subject: $skill);

        return to_route('skills.edit', $skill)->with('success', 'Skill criada. Pode juntar-lhe ficheiros e atribuí-la a agentes.');
    }

    public function edit(Skill $skill): Response
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        return Inertia::render('Skills/Form', [
            'skill' => [
                ...$skill->only(['id', 'key', 'name', 'description', 'instructions', 'is_enabled']),
                'agents' => $skill->agents()->orderBy('name')->get(['agents.id', 'agents.name'])->map(fn ($agent) => ['id' => $agent->id, 'name' => $agent->name]),
            ],
            'files' => $skill->files->map(fn (SkillFile $file) => [
                'id' => $file->id,
                'filename' => $file->filename,
                'size' => $file->size,
                'has_text' => $file->content !== null,
            ]),
        ]);
    }

    public function update(Request $request, Skill $skill): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $skill->update($this->validated($request, $skill));
        AuditLog::record($this->user($request), 'skill.updated', ['key' => $skill->key], subject: $skill);

        return back()->with('success', 'Skill guardada.');
    }

    public function destroy(Request $request, Skill $skill, SkillFiles $files): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $skill->files->each(fn (SkillFile $file) => $files->detach($file));
        AuditLog::record($this->user($request), 'skill.deleted', ['key' => $skill->key]);
        $skill->delete();

        return to_route('skills.index')->with('success', 'Skill apagada.');
    }

    /**
     * Turn a global skill on or off for the company; agents keep the
     * assignment and use it again when it comes back on.
     */
    public function toggleGlobal(Request $request, PlatformSkill $platformSkill): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_unless($platformSkill->is_active, 404);

        $data = $request->validate(['activated' => ['required', 'boolean']]);
        $skill = Skill::query()->firstOrNew(['platform_skill_id' => $platformSkill->id], ['key' => $platformSkill->key]);

        if (! $skill->exists && Skill::query()->where('key', $platformSkill->key)->exists()) {
            $skill->key = $platformSkill->key.'-global';
        }

        $skill->is_enabled = $data['activated'];
        $skill->save();

        AuditLog::record($this->user($request), $data['activated'] ? 'skill.global_activated' : 'skill.global_deactivated', ['key' => $platformSkill->key], subject: $skill);

        return back()->with('success', $data['activated'] ? "{$platformSkill->name} activada." : "{$platformSkill->name} desligada.");
    }

    public function storeFile(Request $request, Skill $skill, SkillFiles $files): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $data = $request->validate(['file' => ['required', ...SkillFiles::RULES]]);
        $file = $files->attach($skill, $data['file']);

        return back()->with($file->content === null ? 'error' : 'success', $file->content === null
            ? "{$file->filename} guardado, mas sem texto legível: o agente não o vai conseguir ler."
            : "{$file->filename} juntado à skill.");
    }

    public function destroyFile(Skill $skill, SkillFile $file, SkillFiles $files): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_unless($file->skill_id === $skill->id, 404);

        $files->detach($file);

        return back()->with('success', 'Ficheiro removido.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Skill $skill = null): array
    {
        $key = TenantRule::unique('skills', 'key');

        if ($skill !== null) {
            $key->ignore($skill->id);
        }

        return $request->validate([
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', $key],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'instructions' => ['required', 'string', 'max:60000'],
            'is_enabled' => ['boolean'],
        ]);
    }
}
