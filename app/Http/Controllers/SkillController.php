<?php

namespace App\Http\Controllers;

use App\Ai\Skills\SkillFiles;
use App\Ai\Skills\SkillVersions;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\PlatformSkill;
use App\Models\Skill;
use App\Models\SkillFile;
use App\Models\SkillVersion;
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
                'all_agents' => $skill->all_agents,
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

    public function store(Request $request, SkillVersions $versions): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $skill = new Skill($this->validated($request));
        $skill->forceFill(['created_by_user_id' => $this->user($request)->id])->save();
        $versions->record($skill, $this->user($request));
        AuditLog::record($this->user($request), 'skill.created', ['key' => $skill->key], subject: $skill);

        return to_route('skills.edit', $skill)->with('success', 'Skill criada. Pode juntar-lhe ficheiros e atribuí-la a agentes.');
    }

    public function edit(Skill $skill): Response
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        return Inertia::render('Skills/Form', [
            'skill' => [
                ...$skill->only(['id', 'key', 'name', 'description', 'instructions', 'is_enabled', 'all_agents']),
                'agents' => $skill->agents()->orderBy('name')->get(['agents.id', 'agents.name', 'agents.avatar_path'])->map(fn (Agent $agent) => ['id' => $agent->id, 'name' => $agent->name, 'avatar_url' => $agent->avatarUrl()]),
            ],
            'agentOptions' => Agent::query()->orderBy('name')->get(['id', 'name', 'title', 'avatar_path'])->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'title' => $agent->title,
                'avatar_url' => $agent->avatarUrl(),
            ]),
            'files' => $skill->files->map(fn (SkillFile $file) => [
                'id' => $file->id,
                'filename' => $file->filename,
                'size' => $file->size,
                'has_text' => $file->content !== null,
            ]),
            'versions' => $this->versionRows($skill),
        ]);
    }

    public function update(Request $request, Skill $skill, SkillVersions $versions): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $data = $this->validated($request, $skill);
        $versions->baseline($skill);
        $skill->update($data);
        $version = $versions->record($skill, $this->user($request));
        AuditLog::record($this->user($request), 'skill.updated', ['key' => $skill->key, 'version' => $version?->number], subject: $skill);

        return back()->with('success', $version !== null ? "Skill guardada (versão {$version->number})." : 'Skill guardada.');
    }

    public function destroy(Request $request, Skill $skill, SkillFiles $files): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        // The skill and its history go together, with every stored file.
        $files->purge($skill);
        AuditLog::record($this->user($request), 'skill.deleted', ['key' => $skill->key, 'versions' => $skill->versions()->reorder()->count()]);
        $skill->delete();

        return to_route('skills.index')->with('success', 'Skill apagada.');
    }

    /**
     * Choose here which agents use the skill, instead of agent by agent.
     */
    public function updateAgents(Request $request, Skill $skill): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $data = $request->validate([
            'all_agents' => ['sometimes', 'boolean'],
            'agents' => ['present', 'array'],
            'agents.*' => ['integer', TenantRule::exists('agents')],
        ]);

        // The chosen ones are kept under «Todos os agentes», for when it is switched off.
        $skill->update(['all_agents' => $data['all_agents'] ?? $skill->all_agents]);
        $changes = $skill->agents()->sync($data['agents']);
        AuditLog::record($this->user($request), 'skill.agents_updated', ['key' => $skill->key, 'all_agents' => $skill->all_agents, 'attached' => $changes['attached'], 'detached' => $changes['detached']], subject: $skill);

        $count = count($data['agents']);

        return back()->with('success', $skill->all_agents ? 'Todos os agentes usam esta skill.' : match ($count) {
            0 => 'Nenhum agente usa esta skill.',
            1 => '1 agente passa a usar esta skill.',
            default => "{$count} agentes passam a usar esta skill.",
        });
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

    public function storeFile(Request $request, Skill $skill, SkillFiles $files, SkillVersions $versions): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $data = $request->validate(['file' => ['required', ...SkillFiles::RULES]]);
        $versions->baseline($skill);
        $file = $files->attach($skill, $data['file']);
        $versions->record($skill, $this->user($request));

        return back()->with($file->content === null ? 'error' : 'success', $file->content === null
            ? "{$file->filename} guardado, mas sem texto legível: o agente não o vai conseguir ler."
            : "{$file->filename} juntado à skill.");
    }

    public function destroyFile(Request $request, Skill $skill, SkillFile $file, SkillFiles $files, SkillVersions $versions): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_unless($file->skill_id === $skill->id, 404);

        $versions->baseline($skill);
        $files->detach($file);
        $versions->record($skill, $this->user($request));

        return back()->with('success', 'Ficheiro removido.');
    }

    /**
     * One version: what it held, and what changed from it to the skill as it
     * is now (docs/DECISOES.md, "Latência, taxa de erro e versões das skills").
     */
    public function version(Skill $skill, int $number, SkillVersions $versions): Response
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $version = $skill->versions()->with('author:id,name')->where('number', $number)->firstOrFail();

        return Inertia::render('Skills/Version', [
            'skill' => $skill->only(['id', 'key', 'name']),
            'version' => $this->versionDetail($version, (int) $skill->versions()->reorder()->max('number')),
            'diff' => $versions->compare($skill, $version),
        ]);
    }

    /**
     * «Repor esta versão»: its content comes back as a new version; the
     * history keeps everything.
     */
    public function restoreVersion(Request $request, Skill $skill, int $number, SkillVersions $versions): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        abort_if($skill->platform_skill_id !== null, 404);

        $version = $skill->versions()->where('number', $number)->firstOrFail();
        $restored = $versions->restore($skill, $version, $this->user($request));

        AuditLog::record($this->user($request), 'skill.version_restored', ['key' => $skill->key, 'restored_from' => $version->number, 'version' => $restored->number], subject: $skill);

        return to_route('skills.edit', $skill)->with('success', "Versão {$version->number} reposta: é agora a versão {$restored->number}.");
    }

    /**
     * @return list<array{number: int, author: string|null, created_at: string|null, restored_from: int|null, is_current: bool}>
     */
    private function versionRows(Skill $skill): array
    {
        $rows = $skill->versions()->with('author:id,name')->limit(100)->get(['id', 'skill_id', 'number', 'restored_from', 'created_by_user_id', 'created_at']);
        $latest = (int) $rows->max('number');

        return $rows->map(fn (SkillVersion $version) => [
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
    private function versionDetail(SkillVersion $version, int $latest): array
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
            'all_agents' => ['boolean'],
        ]);
    }
}
