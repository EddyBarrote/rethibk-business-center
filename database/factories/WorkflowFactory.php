<?php

namespace Database\Factories;

use App\Enums\EmailCategory;
use App\Enums\WorkflowStatus;
use App\Models\Tenant;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Workflow>
 */
class WorkflowFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'name' => fake()->sentence(3),
            'email_category' => EmailCategory::ClientRfq,
            'status' => WorkflowStatus::Draft,
            'graph' => [
                'nodes' => [
                    ['id' => 'start', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Email']],
                    ['id' => 'end', 'type' => 'end', 'position' => ['x' => 0, 'y' => 120], 'data' => ['label' => 'Fim']],
                ],
                'edges' => [['id' => 'e1', 'source' => 'start', 'target' => 'end']],
            ],
        ];
    }
}
