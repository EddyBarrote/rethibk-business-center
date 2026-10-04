<?php

namespace App\Ai\Capabilities;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;

/**
 * Who is running a capability, and on behalf of which run. When a human approved
 * the action, the approval travels too.
 */
final readonly class CapabilityContext
{
    public function __construct(
        public Agent $agent,
        public AgentRun $run,
        public ?Approval $approval = null,
    ) {}
}
