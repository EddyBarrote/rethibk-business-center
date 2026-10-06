<?php

namespace App\Policies;

use App\Models\Department;
use App\Models\User;

class DepartmentPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function create(User $actor): bool
    {
        return $actor->canManageTenant();
    }

    public function update(User $actor, Department $department): bool
    {
        return $actor->canManageTenant() && $actor->tenant_id === $department->tenant_id;
    }

    public function delete(User $actor, Department $department): bool
    {
        return $this->update($actor, $department);
    }
}
