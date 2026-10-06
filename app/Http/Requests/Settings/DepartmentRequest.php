<?php

namespace App\Http\Requests\Settings;

use App\Models\Department;
use App\Tenancy\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department
            ? $this->user()?->can('update', $department) ?? false
            : $this->user()?->can('create', Department::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Department|null $department */
        $department = $this->route('department');

        $unique = TenantRule::unique('departments', 'slug');

        if ($department !== null) {
            $unique->ignore($department->id);
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', $unique],
            'parent_id' => ['nullable', 'integer', TenantRule::exists('departments')],
        ];
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Department|null $department */
                $department = $this->route('department');
                $parentId = $this->integer('parent_id') ?: null;

                if ($department === null || $parentId === null) {
                    return;
                }

                // Walk up from the chosen parent; meeting this department means a cycle.
                $cursor = Department::query()->find($parentId);

                while ($cursor !== null) {
                    if ($cursor->is($department)) {
                        $validator->errors()->add('parent_id', __('Um departamento não pode estar dentro de si próprio.'));

                        return;
                    }

                    $cursor = $cursor->parent;
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => str($this->input('slug') ?: $this->input('name'))->slug()->toString()]);
    }
}
