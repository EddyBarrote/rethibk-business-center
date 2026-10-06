<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentAssignment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentAssignment>
 */
class AgentAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'user_id' => User::factory(),
            'role' => null,
        ];
    }
}
