<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\AgentInitiative;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentInitiative>
 */
class AgentInitiativeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_id' => Agent::factory(),
            'user_id' => User::factory(),
            'title' => 'Preparar a cotação pedida pela Olankha',
            'why' => 'Chegou um pedido de cotação ontem e ainda não tem resposta.',
            'plan' => 'Ler o pedido, ver os preços no ERP e preparar a proposta para rever.',
            'evidence' => [['ref' => 'task:1', 'label' => 'Uma tarefa', 'href' => '/tasks/1']],
            'dedupe_key' => 'task:'.fake()->unique()->numberBetween(1, 999999),
            'status' => AgentInitiative::OPEN,
        ];
    }
}
