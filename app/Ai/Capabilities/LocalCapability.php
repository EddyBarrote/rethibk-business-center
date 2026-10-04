<?php

namespace App\Ai\Capabilities;

use App\Enums\AutonomyLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * A capability implemented in the platform (section 7.1). It is listed in the
 * capabilities table so the super admin can give it to agents and set its risk.
 */
abstract class LocalCapability
{
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function description(): string;

    /**
     * @return array<string, Type>
     */
    abstract public function schema(JsonSchema $schema): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    abstract public function execute(array $arguments, CapabilityContext $context): CapabilityResult;

    /**
     * Writes, sends or moves something (section 5.2).
     */
    public function isMutating(): bool
    {
        return false;
    }

    /**
     * The level an agent needs to run it without approval, until the super
     * admin changes it.
     */
    public function defaultRisk(): AutonomyLevel
    {
        return $this->isMutating() ? AutonomyLevel::ExecuteWithinLimits : AutonomyLevel::Observe;
    }

    /**
     * Why this particular call falls under the absolute ceiling (section
     * 12.3), or null when it does not.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function ceilingReason(array $arguments, CapabilityContext $context): ?string
    {
        return null;
    }

    /**
     * One line for the approval queue.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function summarise(array $arguments): string
    {
        return $this->name();
    }
}
