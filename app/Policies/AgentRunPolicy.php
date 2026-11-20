<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\AgentRun;
use App\Models\User;

/**
 * Who may open a run: its list row, its trace, its live channel and the side
 * panel of a task (docs/DECISOES.md, "Director de Recursos Humanos"). Those
 * with «Ler as conversas de todos», the person who asked for it, and whoever
 * may open its task. A run with neither a task nor a person only the first.
 * What people-data capabilities returned stays hidden from anyone without
 * «Gerir pessoas» even then (PeopleData::present).
 */
class AgentRunPolicy
{
    public function view(User $actor, AgentRun $run): bool
    {
        if ($actor->tenant_id !== $run->tenant_id) {
            return false;
        }

        if ($actor->hasPermission(Permission::ReadAllConversations) || ($run->requested_by_user_id !== null && $run->requested_by_user_id === $actor->id)) {
            return true;
        }

        return $run->task !== null && $actor->can('view', $run->task);
    }
}
