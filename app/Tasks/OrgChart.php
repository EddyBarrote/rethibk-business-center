<?php

namespace App\Tasks;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The org chart, adapted from Paperclip: work is delegated down the chart and
 * escalated up it. People and agents share one chart (docs/DECISOES.md,
 * realinhamento L3): anyone reports to an agent (reports_to_agent_id) or,
 * failing that, to a person (reports_to_user_id). Nodes are "agent:ID" and "user:ID".
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

    public static function key(Agent|User $member): string
    {
        return ($member instanceof Agent ? 'agent:' : 'user:').$member->id;
    }

    /**
     * Who this member reports to, as a node key.
     */
    public function managerOf(Agent|User $member): ?string
    {
        return match (true) {
            $member->reports_to_agent_id !== null => "agent:{$member->reports_to_agent_id}",
            $member->reports_to_user_id !== null => "user:{$member->reports_to_user_id}",
            default => null,
        };
    }

    /**
     * Every node and its manager.
     *
     * @return array<string, string|null>
     */
    public function parents(): array
    {
        $parents = [];

        foreach (Agent::query()->where('status', '!=', AgentStatus::Draft)->get(['id', 'reports_to_agent_id', 'reports_to_user_id']) as $agent) {
            $parents[self::key($agent)] = $this->managerOf($agent);
        }

        foreach (User::query()->where('is_active', true)->get(['id', 'reports_to_agent_id', 'reports_to_user_id']) as $user) {
            $parents[self::key($user)] = $this->managerOf($user);
        }

        return $parents;
    }

    /**
     * Would reporting to $manager put $member above itself?
     */
    public function loops(Agent|User $member, ?string $manager): bool
    {
        if ($manager === null) {
            return false;
        }

        $self = self::key($member);
        $parents = $this->parents();

        for ($node = $manager, $guard = 0; $node !== null && $guard < 50; $node = $parents[$node] ?? null, $guard++) {
            if ($node === $self) {
                return true;
            }
        }

        return false;
    }

    /**
     * Point a member at a new manager ("agent:ID", "user:ID" or null for the top).
     * An agent keeps its responsible person when it reports to another agent.
     */
    public function assign(Agent|User $member, ?string $manager): void
    {
        [$type, $id] = $manager === null ? [null, null] : explode(':', $manager, 2) + [null, null];

        $member->forceFill(match ($type) {
            'agent' => ['reports_to_agent_id' => (int) $id],
            'user' => ['reports_to_agent_id' => null, 'reports_to_user_id' => (int) $id],
            default => ['reports_to_agent_id' => null, 'reports_to_user_id' => null],
        })->save();
    }

    /**
     * The people an agent may give work to: those of its department, the
     * person it answers to and its chart manager (docs/DECISOES.md, realinhamento, decisão 7).
     */
    public function canAssignTo(Agent $agent, User $person): bool
    {
        if (! $person->is_active) {
            return false;
        }

        return $person->id === $agent->reports_to_user_id
            || ($agent->department_id !== null && $person->department_id === $agent->department_id);
    }
}
