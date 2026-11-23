<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentErpIdentity;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentErpIdentity>
 */
class AgentErpIdentityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'token' => 'agent-token-'.fake()->uuid(),
        ];
    }
}
