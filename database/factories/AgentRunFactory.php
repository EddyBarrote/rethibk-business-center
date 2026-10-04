<?php

namespace Database\Factories;

use App\Enums\RunStatus;
use App\Enums\TriggerType;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRun>
 */
class AgentRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'trigger_type' => TriggerType::Manual,
            'status' => RunStatus::Completed,
            'input' => 'Pedido de teste.',
            'output' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'cost_usd' => 0,
            'error' => null,
        ];
    }
}
