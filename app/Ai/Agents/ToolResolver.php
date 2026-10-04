<?php

namespace App\Ai\Agents;

use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillRegistry;
use App\Ai\Tools\GatedTool;
use App\Models\Skill;

/**
 * The agent's tools: its enabled, available skills plus the ones every agent
 * gets, each wrapped in a GatedTool (section 12.2).
 */
final class ToolResolver
{
    /**
     * @return list<GatedTool>
     */
    public function for(SkillContext $context): array
    {
        $enabled = $context->agent->skills()
            ->wherePivot('enabled', true)
            ->where('is_available', true)
            ->get();

        $always = Skill::query()->whereIn('key', SkillRegistry::ALWAYS_ON)->where('is_available', true)->get();

        return $enabled->merge($always)
            ->unique('id')
            ->values()
            ->map(fn (Skill $skill) => new GatedTool($skill, $context))
            ->all();
    }
}
