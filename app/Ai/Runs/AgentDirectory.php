<?php

namespace App\Ai\Runs;

use App\Enums\AgentStatus;
use App\Models\Agent;

/**
 * Finds the agent playing a role (triage, finance...) in the current
 * tenant: the one created from that template, or keyed by it.
 */
final class AgentDirectory
{
    public function forRole(string $role): ?Agent
    {
        return Agent::query()
            ->where('status', AgentStatus::Active)
            ->where(fn ($query) => $query->where('settings->template', $role)->orWhere('key', $role))
            ->get()
            ->sortBy(fn (Agent $agent) => $agent->key === $role ? 0 : 1)
            ->first();
    }
}
