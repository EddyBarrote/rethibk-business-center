<?php

namespace App\Policies;

use App\Models\ErpConnection;
use App\Models\User;

/**
 * The ERP connection holds credentials: owners and admins only.
 */
class ErpConnectionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canManageTenant();
    }

    public function create(User $actor): bool
    {
        return $actor->canManageTenant();
    }

    public function update(User $actor, ErpConnection $connection): bool
    {
        return $actor->canManageTenant() && $actor->tenant_id === $connection->tenant_id;
    }
}
