<?php

namespace Database\Factories;

use App\Models\BudgetEvent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetEvent>
 */
class BudgetEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => null,
            'agent_run_id' => null,
            'scope' => 'tenant',
            'subject_key' => 'tenant',
            'period' => now()->format('Y-m'),
            'threshold' => fake()->unique()->numberBetween(1, 100),
            'spent_usd' => 8,
            'cap_usd' => 10,
        ];
    }
}
