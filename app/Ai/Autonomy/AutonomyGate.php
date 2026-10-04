<?php

namespace App\Ai\Autonomy;

use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillRegistry;
use App\Enums\AutonomyLevel;
use App\Models\Skill;

/**
 * The autonomy gate (section 12.2). The absolute ceiling (section 12.3) is
 * checked first and no autonomy level gets past it; then read-only skills
 * pass, and a mutating skill passes only when the agent's level reaches the
 * skill's risk.
 */
final class AutonomyGate
{
    public function __construct(private readonly SkillRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function evaluate(Skill $skill, array $arguments, SkillContext $context): GateDecision
    {
        $ceiling = $this->ceilingReason($skill, $arguments, $context);

        if ($ceiling !== null) {
            return new GateDecision(false, AutonomyLevel::ExecuteAndReport, $ceiling);
        }

        if (! $skill->is_mutating) {
            return new GateDecision(true, $skill->risk);
        }

        return new GateDecision($context->agent->autonomy_level->value >= $skill->risk->value, $skill->risk);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function ceilingReason(Skill $skill, array $arguments, SkillContext $context): ?string
    {
        /** @var array<string, string> $ceiling */
        $ceiling = config('autonomy.ceiling', []);

        if (isset($ceiling[$skill->key])) {
            return $ceiling[$skill->key];
        }

        return $this->registry->find($skill->key)?->ceilingReason($arguments, $context);
    }
}
