<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentHandoff;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentHandoff>
 */
class AgentHandoffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'user_id' => User::factory(),
            'from_agent_id' => Agent::factory(),
            'to_agent_id' => Agent::factory(),
            'from_task_id' => Task::factory(),
            'to_task_id' => Task::factory(),
            'brief' => fake()->sentence(),
            'status' => AgentHandoff::WORKING,
        ];
    }
}
