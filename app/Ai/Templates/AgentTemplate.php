<?php

namespace App\Ai\Templates;

use App\Enums\AutonomyLevel;

/**
 * A ready-made configuration for one of the six agents of section 6.3. It
 * only fills in a generic agent: after installing, the super admin can
 * change everything.
 */
final readonly class AgentTemplate
{
    /**
     * @param  list<string>  $capabilities  capability keys; ERP ones are prefixed "erp."
     * @param  list<array{name: string, prompt: string, schedule: string}>  $routines
     * @param  list<string>  $retiredRoutines  names of routines earlier versions of the template had, switched off when an agent is brought up to date
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $title,
        public string $description,
        public string $department,
        public AutonomyLevel $autonomy,
        public string $personality,
        public string $instructions,
        public array $capabilities,
        public array $routines,
        public string $mailbox,
        public string $delivery,
        public array $retiredRoutines = [],
    ) {}
}
