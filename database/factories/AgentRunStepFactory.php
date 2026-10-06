<?php

namespace Database\Factories;

use App\Enums\StepType;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRunStep>
 */
class AgentRunStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_run_id' => AgentRun::factory(),
            'seq' => fake()->numberBetween(1, 500),
            'type' => StepType::Message,
            'tool_name' => null,
            'payload' => ['text' => 'Olá.'],
            'duration_ms' => null,
        ];
    }
}
