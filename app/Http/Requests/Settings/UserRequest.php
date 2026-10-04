<?php

namespace App\Http\Requests\Settings;

use App\Enums\Role;
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
            'role' => ['required', Rule::enum(Role::class)],
            'department_id' => ['nullable', 'integer', TenantRule::exists('departments')],
            'is_active' => ['boolean'],
            'password' => [$target === null ? 'required' : 'nullable', 'string', Password::defaults()],
        ];
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
                $role = Role::tryFrom((string) $this->input('role'));

                if ($role !== null && ! $actor->can('assignRole', [User::class, $role])) {
                    $validator->errors()->add('role', __('Não pode atribuir este papel.'));
                }

                if ($target?->is($actor)) {
                    if ($role !== $actor->role) {
                        $validator->errors()->add('role', __('Não pode alterar o seu próprio papel.'));
                    }

                    if (! $this->boolean('is_active', true)) {
                        $validator->errors()->add('is_active', __('Não pode desactivar a sua própria conta.'));
                    }
                }
            },
        ];
    }
}
