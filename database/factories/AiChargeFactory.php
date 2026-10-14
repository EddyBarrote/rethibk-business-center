<?php

namespace Database\Factories;

use App\Models\AiCharge;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCharge>
 */
class AiChargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => null,
            'purpose' => 'knowledge.reading',
            'provider' => 'gemini',
            'model' => 'gemini-3.6-flash',
            'input_tokens' => 1000,
            'output_tokens' => 500,
            'cost_usd' => 0.005,
        ];
    }
}
