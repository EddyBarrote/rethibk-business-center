<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManagePeople);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManagePeople);
    }

    public function update(User $actor, User $user): bool
    {
        if (! $actor->hasPermission(Permission::ManagePeople) || $actor->tenant_id !== $user->tenant_id) {
            return false;
        }

        // Only an owner may change an owner.
        return $user->role !== Role::Owner || $actor->role === Role::Owner;
    }

    /**
     * Whether the actor may give the role to someone. Only owners create owners.
     */
    public function assignRole(User $actor, Role $role): bool
    {
        return $actor->hasPermission(Permission::ManagePeople) && ($role !== Role::Owner || $actor->role === Role::Owner);
    }
}
