<?php

namespace Database\Factories;

use App\Enums\CompanySection;
use App\Models\CompanyFact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyFact>
 */
class CompanyFactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'section' => CompanySection::Rules,
            'content' => fake()->sentence(),
            'status' => CompanyFact::PUBLISHED,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => CompanyFact::PENDING]);
    }
}
