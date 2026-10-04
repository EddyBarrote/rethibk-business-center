<?php

namespace Database\Factories;

use App\Models\KnowledgeDomain;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<KnowledgeDomain>
 */
class KnowledgeDomainFactory extends Factory
{
    public function definition(): array
    {
        $name = Str::title(fake()->unique()->words(2, true));

        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => null,
            'color' => null,
            'department_ids' => null,
            'position' => 0,
        ];
    }

    /**
     * @param  list<int>  $departmentIds
     */
    public function restrictedTo(array $departmentIds): static
    {
        return $this->state(['department_ids' => $departmentIds]);
    }
}
