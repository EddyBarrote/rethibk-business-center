<?php

namespace Database\Factories;

use App\Enums\AgentStatus;
use App\Enums\AutonomyLevel;
use App\Models\Agent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        $name = 'Agente '.fake()->unique()->word();

        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'key' => Str::slug($name, '_'),
            'name' => $name,
            'title' => 'Assistente',
            'description' => null,
            'personality' => 'Directo e cordial.',
            'instructions' => 'Ajuda a equipa com tarefas do dia-a-dia.',
            'department_id' => null,
            'reports_to_user_id' => null,
            'status' => AgentStatus::Active,
            'suspended_reason' => null,
            'autonomy_level' => AutonomyLevel::Observe,
            'provider' => null,
            'model' => null,
            'temperature' => null,
            'max_tokens' => null,
            'max_steps' => null,
            'settings' => null,
        ];
    }

    public function level(AutonomyLevel $level): static
    {
        return $this->state(['autonomy_level' => $level]);
    }

    public function draft(): static
    {
        return $this->state(['status' => AgentStatus::Draft]);
    }

    public function suspended(): static
    {
        return $this->state(['status' => AgentStatus::Suspended, 'suspended_reason' => 'Suspenso em teste.']);
    }
}
