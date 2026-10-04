<?php

namespace App\Policies;

use App\Models\Approval;
use App\Models\User;

/**
 * Who decides on an agent's action (docs/DECISOES.md): owners and admins, the
 * person the agent reports to, and the people assigned to the agent.
 */
class ApprovalPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    /**
     * Sees every approval of the tenant, not only the ones addressed to them.
     */
    public function viewAll(User $actor): bool
    {
        return $actor->canManageTenant();
    }

    public function view(User $actor, Approval $approval): bool
    {
        return $this->decide($actor, $approval);
    }

    public function decide(User $actor, Approval $approval): bool
    {
        if ($actor->tenant_id !== $approval->tenant_id) {
            return false;
        }

        if ($actor->canManageTenant()) {
            return true;
        }

        // Spending more than the organisation decided is for owners and admins.
        if ($approval->action_type === 'budget.override') {
            return false;
        }

        $agent = $approval->agent;

        return $agent->reports_to_user_id === $actor->id
            || $agent->assignees()->whereKey($actor->id)->exists();
    }
}
