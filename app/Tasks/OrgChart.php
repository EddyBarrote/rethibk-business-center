<?php

namespace App\Tasks;

use App\Enums\AgentStatus;
use App\Models\Agent;
use Illuminate\Support\Collection;

/**
 * The agent org chart (agents.reports_to_agent_id), adapted from Paperclip:
 * work is delegated down the chart and escalated up it.
 */
final class OrgChart
{
    public function find(string $keyOrName): ?Agent
    {
        $value = trim($keyOrName);

        return Agent::query()->where('status', '!=', AgentStatus::Draft)
            ->where(fn ($q) => $q->where('key', $value)->orWhere('name', $value))
            ->first();
    }

    /**
     * Down to anyone below, up to the direct manager. An agent outside the
     * chart (no manager and no reports) may ask anyone, so a tenant without a
     * chart still works.
     */
    public function canDelegate(Agent $from, Agent $to): bool
    {
        if ($from->id === $to->id) {
            return false;
        }

        if ($to->id === $from->reports_to_agent_id || $this->below($from)->contains('id', $to->id)) {
            return true;
        }

        return $from->reports_to_agent_id === null && ! $from->directReports()->exists();
    }

    /**
     * Everyone below the agent, at any depth.
     *
     * @return Collection<int, Agent>
     */
    public function below(Agent $agent): Collection
    {
        $all = Agent::query()->get(['id', 'name', 'reports_to_agent_id']);
        $found = collect();
        $frontier = [$agent->id];

        for ($guard = 0; $frontier !== [] && $guard < 20; $guard++) {
            $next = $all->whereIn('reports_to_agent_id', $frontier);
            $found = $found->merge($next);
            $frontier = $next->pluck('id')->all();
        }

        return $found->values();
    }

    /**
     * Would making $manager the boss of $agent create a loop?
     */
    public function createsCycle(Agent $agent, ?int $managerId): bool
    {
        return $managerId !== null && ($managerId === $agent->id || $this->below($agent)->contains('id', $managerId));
    }
}
