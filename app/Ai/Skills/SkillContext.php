<?php

namespace App\Ai\Skills;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;

/**
 * Who is running a skill, and on behalf of which run. When a human approved
 * the action, the approval travels too.
 */
final readonly class SkillContext
{
    public function __construct(
        public Agent $agent,
        public AgentRun $run,
        public ?Approval $approval = null,
    ) {}
}
