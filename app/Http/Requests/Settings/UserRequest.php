<?php

namespace App\Http\Requests\Settings;

use App\Access\AccessRoles;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\AccessRole;
use App\Models\User;
use App\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User
            ? $this->user()?->can('update', $target) ?? false
            : $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $target */
        $target = $this->route('user');

        $unique = TenantRule::unique('users', 'email');

        if ($target !== null) {
            $unique->ignore($target->id);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', $unique],
            // The access matrix (realinhamento L9): a role row; "role" stays for the base level.
            'access_role_id' => ['nullable', 'integer', TenantRule::exists('access_roles')],
            'role' => ['required_without:access_role_id', 'nullable', Rule::enum(Role::class)],
            'job_title' => ['nullable', 'string', 'max:120'],
            'permission_overrides' => ['nullable', 'array'],
            'permission_overrides.*' => ['nullable', 'boolean'],
            'department_id' => ['nullable', 'integer', TenantRule::exists('departments')],
            'is_active' => ['boolean'],
            'password' => [$target === null ? 'required' : 'nullable', 'string', Password::defaults()],
        ];
    }

    /**
     * The base level: the chosen role row's, or the "role" field.
     */
    public function baseRole(): ?Role
    {
        $accessRole = $this->filled('access_role_id') ? AccessRole::query()->find((int) $this->input('access_role_id')) : null;

        return $accessRole->base ?? Role::tryFrom((string) $this->input('role'));
    }

    /**
     * The fields to save, with the base level kept in step and only known
     * permissions in the exceptions.
     *
     * @return array<string, mixed>
     */
    public function toSave(): array
    {
        $data = $this->validated();
        $data['role'] = $this->baseRole();

        if (array_key_exists('permission_overrides', $data)) {
            $known = array_map(fn (Permission $p) => $p->value, Permission::cases());
            $data['permission_overrides'] = array_filter(
                array_intersect_key((array) $data['permission_overrides'], array_flip($known)),
                fn ($value) => is_bool($value),
            ) ?: null;
        }

        if (! array_key_exists('access_role_id', $data) || $data['access_role_id'] === null) {
            $data['access_role_id'] = app(AccessRoles::class)->forBase($data['role'])->id;
        }

        return $data;
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $actor */
                $actor = $this->user();
                /** @var User|null $target */
                $target = $this->route('user');
                $role = $this->baseRole();

                if ($role !== null && ! $actor->can('assignRole', [User::class, $role])) {
                    $validator->errors()->add('role', __('Não pode atribuir este papel.'));
                }

                if ($target?->is($actor)) {
                    if ($role !== $actor->role || ($this->filled('access_role_id') && (int) $this->input('access_role_id') !== $actor->access_role_id && $actor->access_role_id !== null)) {
                        $validator->errors()->add('role', __('Não pode alterar o seu próprio papel.'));
                    }

                    if ($this->has('permission_overrides') && ($this->input('permission_overrides') ?: null) != ($actor->permission_overrides ?: null)) {
                        $validator->errors()->add('permission_overrides', __('Não pode alterar as suas próprias excepções.'));
                    }

                    if (! $this->boolean('is_active', true)) {
                        $validator->errors()->add('is_active', __('Não pode desactivar a sua própria conta.'));
                    }
                }
            },
        ];
    }
}
