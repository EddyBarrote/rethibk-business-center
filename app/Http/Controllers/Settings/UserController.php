<?php

namespace App\Http\Controllers\Settings;

use App\Access\AccessRoles;
use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UserRequest;
use App\Models\AccessRole;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', User::class);

        $users = User::query()
            ->with(['department:id,name', 'accessRole:id,name'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->accessRole->name ?? $user->role->label(),
                'department' => $user->department?->name,
                'is_active' => $user->is_active,
                'last_seen_at' => $user->last_seen_at?->toIso8601String(),
            ]);

        return Inertia::render('Settings/Users/Index', ['users' => $users]);
    }

    public function create(): Response
    {
        Gate::authorize('create', User::class);

        return Inertia::render('Settings/Users/Form', $this->formOptions());
    }

    public function store(UserRequest $request): RedirectResponse
    {
        Gate::authorize('create', User::class);

        User::query()->create($request->toSave());

        return to_route('settings.users.index')->with('success', __('Utilizador criado.'));
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Settings/Users/Form', [
            ...$this->formOptions(),
            'user' => $user->only(['id', 'name', 'job_title', 'email', 'department_id', 'is_active']) + [
                'role' => $user->role->value,
                'access_role_id' => $user->access_role_id ?? app(AccessRoles::class)->forBase($user->role)->id,
                'permission_overrides' => (object) ($user->permission_overrides ?? []),
            ],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $data = $request->toSave();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $user->update($data);

        return to_route('settings.users.index')->with('success', __('Utilizador actualizado.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function formOptions(): array
    {
        app(AccessRoles::class)->ensure();

        return [
            'roles' => Role::options(),
            'access_roles' => AccessRole::query()->orderByDesc('is_system')->orderBy('id')->get(['id', 'name', 'base', 'permissions']),
            'permissions' => Permission::options(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
