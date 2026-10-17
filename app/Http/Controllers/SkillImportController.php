<?php

namespace App\Http\Controllers;

use App\Ai\Skills\SkillImports;
use App\Models\Skill;
use App\Tenancy\TenantManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bringing in skills made for Claude, Codex and similar tools
 * (docs/CAPACIDADES.md, "Importar skills"): upload, see what came in and
 * what was left out, adjust, then create.
 */
class SkillImportController extends Controller
{
    public function __construct(private readonly SkillImports $imports, private readonly TenantManager $tenants) {}

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-catalog');

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:51200', 'extensions:md,markdown,txt,zip,skill'],
        ], [
            'files.*.extensions' => 'Envie um SKILL.md, um .zip ou um .skill.',
            'files.*.max' => 'Cada ficheiro pode ter até 50 MB.',
        ]);

        $token = $this->imports->stage($this->tenantId(), $this->user($request), array_values($request->file('files')));

        return to_route('skills.import.show', $token);
    }

    public function show(string $token): Response
    {
        Gate::authorize('manage-catalog');
        $manifest = $this->imports->load($this->tenantId(), $token) ?? abort(404);

        return Inertia::render('Skills/Import', [
            'token' => $token,
            'skills' => collect($manifest['skills'])->map(fn (array $skill) => [
                'index' => $skill['index'],
                'source' => $skill['source'],
                'folder' => $skill['folder'],
                'key' => $skill['key'],
                'key_taken' => $skill['key_taken'],
                'name' => $skill['name'],
                'description' => $skill['description'],
                'instructions' => $skill['instructions'],
                'files' => array_map(fn (array $file) => ['filename' => $file['filename'], 'size' => $file['size']], $skill['files']),
                'left_out' => $skill['left_out'],
                'notes' => $skill['notes'],
            ])->values(),
            'problems' => $manifest['problems'],
        ]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        $manifest = $this->imports->load($this->tenantId(), $token) ?? abort(404);

        $data = $request->validate([
            'skills' => ['required', 'array', 'min:1'],
            'skills.*.index' => ['required', 'integer'],
            'skills.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'distinct'],
            'skills.*.name' => ['required', 'string', 'max:255'],
            'skills.*.description' => ['required', 'string', 'max:1000'],
        ], [
            'skills.required' => 'Escolha pelo menos uma skill para importar.',
            'skills.*.description.required' => 'Escreva o «quando usar»: é por ele que o agente decide.',
            'skills.*.key.distinct' => 'Duas skills com a mesma chave.',
        ]);

        $indexes = array_column($manifest['skills'], 'index');
        $taken = Skill::query()->pluck('key')->all();
        $choices = [];

        foreach ($data['skills'] as $position => $choice) {
            if (! in_array($choice['index'], $indexes, true)) {
                abort(422);
            }

            if (in_array($choice['key'], $taken, true)) {
                throw ValidationException::withMessages(["skills.{$position}.key" => "Já há uma skill com a chave {$choice['key']}."]);
            }

            $choices[$choice['index']] = $choice;
        }

        $created = $this->imports->commit($this->tenantId(), $token, $this->user($request), $choices);

        if (count($created) === 1) {
            return to_route('skills.edit', $created[0])->with('success', 'Skill importada. Ainda não está atribuída a nenhum agente: escolha quem a usa.');
        }

        return to_route('skills.index')->with('success', count($created).' skills importadas. Ainda não estão atribuídas a nenhum agente: escolha quem as usa.');
    }

    public function destroy(string $token): RedirectResponse
    {
        Gate::authorize('manage-catalog');
        $this->imports->discard($this->tenantId(), $token);

        return to_route('skills.index');
    }

    private function tenantId(): int
    {
        return $this->tenants->currentOrFail()->id;
    }
}
