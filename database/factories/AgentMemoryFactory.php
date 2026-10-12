<?php

namespace Database\Factories;

use App\Enums\MemorySection;
use App\Models\Agent;
use App\Models\AgentMemory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentMemory>
 */
class AgentMemoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'section' => MemorySection::Topics,
            'title' => fake()->unique()->words(3, true),
            'description' => fake()->sentence(),
            'content' => fake()->sentence(),
            'kind' => AgentMemory::WORK,
        ];
    }
}
