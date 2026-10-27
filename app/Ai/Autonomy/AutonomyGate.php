<?php

namespace App\Ai\Autonomy;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Enums\AutonomyLevel;
use App\Enums\RuleEffect;
use App\Models\Capability;

/**
 * The autonomy gate (section 12.2). A rule that refuses comes first; then the
 * absolute ceiling (section 12.3), which no level and no rule gets past; then
 * the standing rules (docs/DECISOES.md, "Regras de aprovação"): «Permitir
 * sempre» lets the action through, «Perguntar sempre» holds it. Without a
 * rule, read-only capabilities pass, and a mutating capability passes only
 * when the agent's level reaches the capability's risk.
 */
final class AutonomyGate
{
    public function __construct(private readonly CapabilityRegistry $registry, private readonly ApprovalRules $rules) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function evaluate(Capability $capability, array $arguments, CapabilityContext $context): GateDecision
    {
        $rule = $this->rules->for($context->agent, $capability->key);

        if ($rule?->effect === RuleEffect::Deny) {
            return new GateDecision(false, $capability->risk, rule: $rule, denied: true);
        }

        $ceiling = $this->ceilingReason($capability, $arguments, $context);

        if ($ceiling !== null) {
            return new GateDecision(false, AutonomyLevel::ExecuteAndReport, $ceiling, $rule?->effect === RuleEffect::Ask ? $rule : null);
        }

        if ($rule !== null) {
            return new GateDecision($rule->effect === RuleEffect::Allow, $capability->risk, rule: $rule);
        }

        if (! $capability->is_mutating) {
            return new GateDecision(true, $capability->risk);
        }

        return new GateDecision($context->agent->autonomy_level->value >= $capability->risk->value, $capability->risk);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function ceilingReason(Capability $capability, array $arguments, CapabilityContext $context): ?string
    {
        /** @var array<string, string> $ceiling */
        $ceiling = config('autonomy.ceiling', []);

        if (isset($ceiling[$capability->key])) {
            return $ceiling[$capability->key];
        }

        return $this->registry->find($capability->key)?->ceilingReason($arguments, $context);
    }
}
