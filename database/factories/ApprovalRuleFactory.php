<?php

namespace Database\Factories;

use App\Enums\RuleEffect;
use App\Models\ApprovalRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRule>
 */
class ApprovalRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => null,
            'capability_key' => 'tasks.create.'.fake()->unique()->numberBetween(1, 99999),
            'effect' => RuleEffect::Ask,
        ];
    }
}
