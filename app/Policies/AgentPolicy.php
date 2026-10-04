<?php

namespace App\Policies;

use App\Models\Agent;
use App\Models\User;

/**
 * Inside a tenant, agents are seen by everyone and run by the people who work
 * with them. Their definition belongs to the super admin (docs/DECISOES.md);
 * owners and admins can suspend, reactivate and assign.
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

    public function manage(User $actor, Agent $agent): bool
    {
        return $this->view($actor, $agent) && $actor->canManageTenant();
    }
}
