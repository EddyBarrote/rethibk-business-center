<?php

namespace App\Policies;

use App\Access\AgentAccess;
use App\Enums\Permission;
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

    /**
     * Talk to the agent. Nobody does until given access, agent by agent
     * (docs/DECISOES.md, realinhamento L8), except the person it answers to
     * and roles that talk to every agent.
     */
    public function run(User $actor, Agent $agent): bool
    {
        return $this->view($actor, $agent) && $agent->isActive() && $this->level($actor, $agent) !== null;
    }

    /**
     * Ask the agent for work (tasks, direct actions), the second access level.
     */
    public function requestWork(User $actor, Agent $agent): bool
    {
        return $this->view($actor, $agent) && $agent->isActive() && $this->level($actor, $agent) === AgentAccess::WORK;
    }

    /**
     * Say who talks to the agent: administrators, and people who grant access
     * for the agents of their own department.
     */
    public function manageAccess(User $actor, Agent $agent): bool
    {
        return $this->view($actor, $agent) && ($actor->canManageTenant()
            || ($actor->hasPermission(Permission::GrantAgentAccess) && $actor->department_id !== null && $actor->department_id === $agent->department_id));
    }

    private function level(User $actor, Agent $agent): ?string
    {
        if ($actor->hasPermission(Permission::TalkToAllAgents) || $agent->reports_to_user_id === $actor->id) {
            return AgentAccess::WORK;
        }

        return AgentAccess::levelOf($agent, $actor);
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
