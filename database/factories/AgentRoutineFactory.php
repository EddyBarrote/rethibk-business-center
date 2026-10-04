<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRoutine>
 */
class AgentRoutineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'name' => 'Resumo diário',
            'prompt' => 'Resume o que aconteceu ontem.',
            'schedule' => '30 6 * * 1-5',
            'is_active' => true,
            'last_run_at' => null,
        ];
    }
}
