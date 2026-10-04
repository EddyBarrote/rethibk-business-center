<?php

namespace Database\Factories;

use App\Enums\ApprovalStatus;
use App\Enums\AutonomyLevel;
use App\Enums\ExecutionStatus;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'agent_run_id' => AgentRun::factory(),
            'agent_id' => Agent::factory(),
            'skill_id' => null,
            'action_type' => 'erp.leads.create',
            'action_summary' => 'Criar uma lead',
            'payload' => [],
            'required_level' => AutonomyLevel::ExecuteWithinLimits,
            'agent_level' => AutonomyLevel::Suggest,
            'status' => ApprovalStatus::Pending,
            'execution_status' => ExecutionStatus::NotExecuted,
        ];
    }
}
