<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentCapability;
use App\Models\Capability;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentCapability>
 */
class AgentCapabilityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'capability_id' => Capability::factory(),
            'enabled' => true,
            'config' => null,
        ];
    }
}
