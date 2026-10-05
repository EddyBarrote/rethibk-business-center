<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessRoles;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\AccessRole;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The access matrix (docs/DECISOES.md, realinhamento L9): roles down the
 * side, permissions across the top. Whoever administers the company edits it.
 */
class AccessRoleController extends Controller
{
    public function index(Request $request, AccessRoles $roles): Response
    {
        abort_unless($this->user($request)->canManageTenant(), 403);
        $roles->ensure();

        return Inertia::render('Settings/Roles', [
            'roles' => AccessRole::query()->withCount('users')->orderByDesc('is_system')->orderBy('id')->get()->map(fn (AccessRole $role) => [
                'id' => $role->id,
                'key' => $role->key,
                'name' => $role->name,
                'permissions' => $role->permissions,
                'is_system' => $role->is_system,
                'users_count' => $role->users_count,
            ]),
            'permissions' => Permission::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        $data = $this->validated($request);

        $role = AccessRole::query()->create([
            'key' => Str::slug($data['name']).'-'.Str::lower(Str::random(4)),
            'name' => $data['name'],
            'permissions' => $data['permissions'],
            'base' => AccessRoles::baseFor($data['permissions']),
        ]);
        AuditLog::record($user, 'access_role.created', ['role' => $role->name, 'permissions' => $role->permissions], subject: $role);

        return back()->with('success', 'Papel criado.');
    }

    public function update(Request $request, AccessRole $role): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        $data = $this->validated($request);

        // The CEO role always administers the company, so nobody locks everyone out.
        if ($role->key === 'ceo' && ! in_array(Permission::ManageCompany->value, $data['permissions'], true)) {
            throw ValidationException::withMessages(['permissions' => 'O papel de CEO administra sempre a empresa.']);
        }

        $role->fill([
            'name' => $data['name'],
            'permissions' => $data['permissions'],
            'base' => $role->is_system ? $role->base : AccessRoles::baseFor($data['permissions']),
        ])->save();

        if (! $role->is_system) {
            $role->users()->update(['role' => $role->base]);
        }

        AuditLog::record($user, 'access_role.updated', ['role' => $role->name, 'permissions' => $role->permissions], subject: $role);

        return back()->with('success', 'Papel actualizado.');
    }

    public function destroy(Request $request, AccessRole $role): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);

        if ($role->is_system || $role->users()->exists()) {
            throw ValidationException::withMessages(['role' => 'Só se apagam papéis criados por si e sem pessoas.']);
        }

        AuditLog::record($user, 'access_role.deleted', ['role' => $role->name], subject: $role);
        $role->delete();

        return back()->with('success', 'Papel apagado.');
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::enum(Permission::class)],
        ]);

        return ['name' => $data['name'], 'permissions' => array_values(array_unique($data['permissions']))];
    }
}
