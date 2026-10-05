<?php

namespace App\Ai\Agents;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Skills\AgentSkills;
use App\Ai\Tools\GatedTool;
use App\Models\Capability;
use App\Models\MailboxReader;

/**
 * The agent's tools: its enabled, available capabilities plus the ones every agent
 * gets (and the skill tools when it has skills), each wrapped in a GatedTool (section 12.2).
 */
final class ToolResolver
{
    public function __construct(private readonly AgentSkills $skills) {}

    /**
     * @return list<GatedTool>
     */
    public function for(CapabilityContext $context): array
    {
        $enabled = $context->agent->capabilities()
            ->wherePivot('enabled', true)
            ->where('is_available', true)
            ->where('is_enabled', true)
            ->get();

        $alwaysOn = CapabilityRegistry::alwaysOn();

        if (in_array('chief_of_staff', [$context->agent->key, $context->agent->settings['template'] ?? null], true)) {
            $alwaysOn = [...$alwaysOn, ...CapabilityRegistry::CHIEF_OF_STAFF];
        }

        if (MailboxReader::query()->where('agent_id', $context->agent->id)->exists()) {
            $alwaysOn = [...$alwaysOn, ...CapabilityRegistry::MAILBOX_READER];
        }

        if ($this->skills->for($context->agent)->isNotEmpty()) {
            $alwaysOn = [...$alwaysOn, ...CapabilityRegistry::SKILL_TOOLS];
        }

        $always = Capability::query()->whereIn('key', $alwaysOn)->where('is_available', true)->get();

        return $enabled->merge($always)
            ->unique('id')
            ->values()
            ->map(fn (Capability $capability) => new GatedTool($capability, $context))
            ->all();
    }
}
