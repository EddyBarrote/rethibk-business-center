<?php

namespace Database\Factories;

use App\Enums\CollaboratorRole;
use App\Models\Task;
use App\Models\TaskCollaborator;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskCollaborator>
 */
class TaskCollaboratorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'task_id' => Task::factory(),
            'user_id' => User::factory(),
            'role' => CollaboratorRole::Review,
        ];
    }
}
