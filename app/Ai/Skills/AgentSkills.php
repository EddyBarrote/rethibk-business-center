<?php

namespace App\Ai\Skills;

use App\Models\Agent;
use App\Models\Skill;
use Illuminate\Support\Collection;

/**
 * The skills an agent can use right now (docs/CAPACIDADES.md): assigned to
 * it or given to every agent, switched on in the tenant and, if global,
 * still offered.
 */
final class AgentSkills
{
    /**
     * @return Collection<int, Skill>
     */
    public function for(Agent $agent): Collection
    {
        $assigned = $agent->skills()->with(['platformSkill.files', 'files'])->where('is_enabled', true)->get();
        $everyone = Skill::query()->with(['platformSkill.files', 'files'])->where('tenant_id', $agent->tenant_id)->where('is_enabled', true)->where('all_agents', true)->get();

        return $assigned->concat($everyone)
            ->unique('id')
            ->filter(fn (Skill $skill) => $skill->isUsable())
            ->sortBy(fn (Skill $skill) => $skill->displayName())
            ->values();
    }

    public function find(Agent $agent, string $key): ?Skill
    {
        return $this->for($agent)->first(fn (Skill $skill) => $skill->key === $key);
    }
}
