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
     * @param  list<string>  $skills  skill keys; ERP ones are prefixed "erp."
     * @param  list<array{name: string, prompt: string, schedule: string}>  $routines
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
        public array $skills,
        public array $routines,
        public string $mailbox,
        public string $delivery,
    ) {}
}
