<?php

namespace Database\Factories;

use App\Enums\WorkflowRunStatus;
use App\Models\Tenant;
use App\Models\Workflow;
use App\Models\WorkflowRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkflowRun>
 */
class WorkflowRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'workflow_id' => Workflow::factory(),
            'graph' => ['nodes' => [], 'edges' => []],
            'status' => WorkflowRunStatus::Running,
            'started_at' => now(),
        ];
    }
}
