<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Direcção '.fake()->unique()->word();

        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'parent_id' => null,
        ];
    }
}
