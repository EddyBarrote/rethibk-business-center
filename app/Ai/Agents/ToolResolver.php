<?php

namespace App\Ai\Agents;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Tools\GatedTool;
use App\Models\Capability;

/**
 * The agent's tools: its enabled, available capabilities plus the ones every agent
 * gets, each wrapped in a GatedTool (section 12.2).
 */
final class ToolResolver
{
    /**
     * @return list<GatedTool>
     */
    public function for(CapabilityContext $context): array
    {
        $enabled = $context->agent->capabilities()
            ->wherePivot('enabled', true)
            ->where('is_available', true)
            ->get();

        $always = Capability::query()->whereIn('key', CapabilityRegistry::alwaysOn())->where('is_available', true)->get();

        return $enabled->merge($always)
            ->unique('id')
            ->values()
            ->map(fn (Capability $capability) => new GatedTool($capability, $context))
            ->all();
    }
}
