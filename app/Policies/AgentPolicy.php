<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

/**
 * Inside a tenant, agents are seen by everyone and run by the people who work
 * with them. Owners and admins create and edit them (as the super admin can),
 * suspend, reactivate and assign them (docs/CAPACIDADES.md).
 */
class AgentPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Agent $agent): bool
    {
        return $actor->tenant_id === $agent->tenant_id;
    }

    public function run(User $actor, Agent $agent): bool
    {
        return $this->view($actor, $agent) && $agent->isActive() && (
            $actor->canManageTenant()
            || $agent->reports_to_user_id === $actor->id
            || $agent->assignees()->whereKey($actor->id)->exists()
        );
    }

    public function create(User $actor): bool
    {
        return $actor->canManageTenant();
    }

    public function update(User $actor, Agent $agent): bool
    {
        return $this->manage($actor, $agent);
    }

    public function manage(User $actor, Agent $agent): bool
    {
        return $this->view($actor, $agent) && $actor->canManageTenant();
    }
}
