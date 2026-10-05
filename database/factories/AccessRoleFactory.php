<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\AccessRole;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessRole>
 */
class AccessRoleFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->jobTitle();

        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'key' => Str::slug($name).'-'.Str::random(4),
            'name' => $name,
            'base' => Role::Member,
            'permissions' => [],
            'is_system' => false,
        ];
    }
}
