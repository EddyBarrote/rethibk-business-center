<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workflow;

/**
 * Everyone sees the flows; they are drawn and changed by the people who give
 * access to the flow's agent: administrators, and those who grant access to
 * the agents of their own department (docs/DECISOES.md, "Fluxos de trabalho").
 */
class WorkflowPolicy
{
    public function viewAny(User $actor): bool
    {
        return true;
    }

    public function view(User $actor, Workflow $workflow): bool
    {
        return $actor->tenant_id === $workflow->tenant_id;
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permission::ManageAgents) || $actor->hasPermission(Permission::GrantAgentAccess);
    }

    public function update(User $actor, Workflow $workflow): bool
    {
        if (! $this->view($actor, $workflow)) {
            return false;
        }

        return $workflow->agent === null ? $actor->hasPermission(Permission::ManageAgents) : $this->useAgent($actor, $workflow->agent);
    }

    public function delete(User $actor, Workflow $workflow): bool
    {
        return $this->update($actor, $workflow);
    }

    /**
     * Whether this person may give a flow to the agent.
     */
    public function useAgent(User $actor, Agent $agent): bool
    {
        return (new AgentPolicy)->manageAccess($actor, $agent);
    }
}
