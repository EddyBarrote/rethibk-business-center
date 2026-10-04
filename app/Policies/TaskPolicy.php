<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

/**
 * Owners and admins see every task. Everyone else sees the threads they are
 * part of and the work of the agents they answer for or work with.
 */
class TaskPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function viewAll(User $actor): bool
    {
        return $actor->canManageTenant();
    }

    public function view(User $actor, Task $task): bool
    {
        if ($actor->tenant_id !== $task->tenant_id) {
            return false;
        }

        if ($actor->canManageTenant() || in_array($actor->id, [$task->user_id, $task->created_by_user_id], true)) {
            return true;
        }

        $agent = $task->assigneeAgent;

        return $agent !== null && ($agent->reports_to_user_id === $actor->id || $agent->assignees()->whereKey($actor->id)->exists());
    }

    /**
     * Writing in the thread wakes its agent, so it needs the right to run that agent.
     */
    public function reply(User $actor, Task $task): bool
    {
        return $this->view($actor, $task) && ($task->assigneeAgent === null || $actor->can('run', $task->assigneeAgent));
    }

    public function update(User $actor, Task $task): bool
    {
        return $this->view($actor, $task);
    }
}
