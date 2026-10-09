<?php

namespace Database\Factories;

use App\Enums\QuestionStatus;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\AgentRun;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentQuestion>
 */
class AgentQuestionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_run_id' => AgentRun::factory(),
            'agent_id' => Agent::factory(),
            'questions' => [[
                'question' => 'Em que formato quer o relatório?',
                'header' => 'Formato',
                'options' => [
                    ['label' => 'PDF', 'description' => 'Para enviar por email.'],
                    ['label' => 'Word', 'description' => 'Para editar.'],
                ],
                'multi_select' => false,
            ]],
            'status' => QuestionStatus::Pending,
        ];
    }
}
