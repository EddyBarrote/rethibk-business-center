<?php

namespace App\Http\Controllers\Knowledge;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Department;
use App\Models\KnowledgeDomain;
use App\Tenancy\TenantRule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Information domains and who may open them. Owners and admins only.
 */
class DomainController extends Controller
{
    public function __construct(private readonly KnowledgeAccess $access) {}

    public function index(Request $request): Response
    {
        abort_unless($this->user($request)->canManageTenant(), 403);
        $this->access->ensureDefaults();

        return Inertia::render('Knowledge/Domains', [
            'domains' => KnowledgeDomain::query()->withCount(['items', 'folders'])->orderBy('position')->orderBy('name')->get()->map(fn (KnowledgeDomain $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'slug' => $d->slug,
                'description' => $d->description,
                'color' => $d->color,
                'department_ids' => $d->department_ids,
                'items' => $d->items_count,
                'folders' => $d->folders_count,
            ]),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        $data = $this->validated($request);
        $slug = Str::slug($data['name']);
        abort_if(KnowledgeDomain::query()->where('slug', $slug)->exists(), 422, 'Já existe um domínio com esse nome.');

        $domain = KnowledgeDomain::query()->create([...$data, 'slug' => $slug, 'position' => (int) KnowledgeDomain::query()->max('position') + 1]);
        AuditLog::record($user, 'knowledge.domain_created', ['name' => $domain->name, 'department_ids' => $domain->department_ids], subject: $domain);

        return back()->with('success', 'Domínio criado.');
    }

    public function update(Request $request, KnowledgeDomain $domain): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        $domain->update($this->validated($request));
        AuditLog::record($user, 'knowledge.domain_updated', ['name' => $domain->name, 'department_ids' => $domain->department_ids], subject: $domain);

        return back()->with('success', 'Domínio actualizado.');
    }

    public function destroy(Request $request, KnowledgeDomain $domain): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);

        // A restricted domain's documents must never fall into the open.
        if ($domain->items()->exists()) {
            return back()->with('error', 'Mova ou apague primeiro os documentos deste domínio.');
        }

        AuditLog::record($user, 'knowledge.domain_deleted', ['name' => $domain->name]);
        $domain->delete();

        return back()->with('success', 'Domínio apagado.');
    }

    /**
     * @return array{name: string, description: string|null, color: string|null, department_ids: list<int>|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'restricted' => ['boolean'],
            'department_ids' => ['array'],
            'department_ids.*' => ['integer', TenantRule::exists('departments')],
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? null,
            'department_ids' => ($data['restricted'] ?? false) ? array_values(array_map('intval', $data['department_ids'] ?? [])) : null,
        ];
    }
}
