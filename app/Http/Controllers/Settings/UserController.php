<?php

namespace App\Http\Controllers\Settings;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UserRequest;
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
            ->with('department:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->value,
                'role_label' => $user->role->label(),
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

        User::query()->create($request->validated());

        return to_route('settings.users.index')->with('success', __('Utilizador criado.'));
    }

    public function edit(User $user): Response
    {
        Gate::authorize('update', $user);

        return Inertia::render('Settings/Users/Form', [
            ...$this->formOptions(),
            'user' => $user->only(['id', 'name', 'email', 'department_id', 'is_active']) + ['role' => $user->role->value],
        ]);
    }

    public function update(UserRequest $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $data = $request->validated();

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
        return [
            'roles' => Role::options(),
            'departments' => Department::query()->orderBy('name')->get(['id', 'name']),
        ];
    }
}
