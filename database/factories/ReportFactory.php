<?php

namespace Database\Factories;

use App\Models\Report;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'type' => 'other',
            'title' => 'Relatório '.fake()->words(3, true),
            'content' => fake()->paragraph(),
            'status' => 'draft',
        ];
    }
}
