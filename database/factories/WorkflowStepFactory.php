<?php

namespace Database\Factories;

use App\Enums\WorkflowStepStatus;
use App\Models\Tenant;
use App\Models\WorkflowRun;
use App\Models\WorkflowStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowStep>
 */
class WorkflowStepFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'workflow_run_id' => WorkflowRun::factory(),
            'node_id' => 'n'.fake()->numberBetween(1, 99),
            'kind' => 'agent',
            'status' => WorkflowStepStatus::Active,
            'started_at' => now(),
        ];
    }
}
