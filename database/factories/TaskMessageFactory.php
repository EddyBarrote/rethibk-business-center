<?php

namespace Database\Factories;

use App\Enums\ActorType;
use App\Enums\TaskMessageKind;
use App\Models\Task;
use App\Models\TaskMessage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskMessage>
 */
class TaskMessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'task_id' => Task::factory(),
            'author_type' => ActorType::System,
            'kind' => TaskMessageKind::Message,
            'body' => fake()->sentence(),
        ];
    }
}
