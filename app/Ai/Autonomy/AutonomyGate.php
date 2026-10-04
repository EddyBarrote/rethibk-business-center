<?php

namespace App\Ai\Autonomy;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Enums\AutonomyLevel;
use App\Models\Capability;

/**
 * The autonomy gate (section 12.2). The absolute ceiling (section 12.3) is
 * checked first and no autonomy level gets past it; then read-only capabilities
 * pass, and a mutating capability passes only when the agent's level reaches the
 * capability's risk.
 */
final class AutonomyGate
{
    public function __construct(private readonly CapabilityRegistry $registry) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function evaluate(Capability $capability, array $arguments, CapabilityContext $context): GateDecision
    {
        $ceiling = $this->ceilingReason($capability, $arguments, $context);

        if ($ceiling !== null) {
            return new GateDecision(false, AutonomyLevel::ExecuteAndReport, $ceiling);
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
