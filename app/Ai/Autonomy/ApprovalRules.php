<?php

namespace App\Ai\Autonomy;

use App\Enums\RuleEffect;
use App\Models\Agent;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\User;

/**
 * Standing rules on top of the autonomy levels (docs/DECISOES.md, "Regras de
 * aprovação"). The agent's own rule beats the rule for all agents. A rule
 * never lifts the absolute ceiling nor the agent's value limit, and the actions a permission decides
 * (budget, trust, new agents and skills) take no «Permitir sempre».
 */
final class ApprovalRules
{
    public function for(Agent $agent, string $capabilityKey): ?ApprovalRule
    {
        return ApprovalRule::query()
            ->with('agent:id,name')
            ->where('capability_key', $capabilityKey)
            ->where(fn ($q) => $q->where('agent_id', $agent->id)->orWhereNull('agent_id'))
            ->get()
            ->sortBy(fn (ApprovalRule $rule) => $rule->agent_id === null ? 1 : 0)
            ->first();
    }

    /**
     * Can a standing «Permitir sempre» come out of this approval? Not above
     * the value limit: the rule would never let that amount through.
     */
    public static function canAllowAlways(Approval $approval): bool
    {
        return $approval->ceiling_reason === null && $approval->value_limit_reason === null && ! array_key_exists($approval->action_type, Approval::DECIDED_BY_PERMISSION);
    }

    /**
     * «Permitir sempre» on an approval: from now on this agent does this action
     * without asking.
     */
    public function allowAlways(Approval $approval, User $user): ApprovalRule
    {
        return ApprovalRule::query()->updateOrCreate(
            ['agent_id' => $approval->agent_id, 'capability_key' => $approval->action_type],
            ['effect' => RuleEffect::Allow, 'created_by_user_id' => $user->id, 'from_approval_id' => $approval->id],
        );
    }
}
