<?php

namespace Database\Factories;

use App\Enums\TaskKind;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'kind' => TaskKind::Task,
            'title' => fake()->sentence(5),
            'status' => TaskStatus::Todo,
            'priority' => TaskPriority::Normal,
        ];
    }

    public function chat(): static
    {
        return $this->state(['kind' => TaskKind::Chat, 'status' => TaskStatus::InProgress]);
    }
}
