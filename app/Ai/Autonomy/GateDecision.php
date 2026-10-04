<?php

namespace App\Ai\Autonomy;

use App\Enums\AutonomyLevel;

final readonly class GateDecision
{
    public function __construct(
        public bool $allowed,
        public AutonomyLevel $requiredLevel,
        public ?string $ceilingReason = null,
    ) {}

    public function reason(): string
    {
        if ($this->allowed) {
            return 'Permitido.';
        }

        return $this->ceilingReason !== null
            ? "Tecto absoluto: {$this->ceilingReason}."
            : "Exige nível {$this->requiredLevel->code()} para executar sem aprovação.";
    }
}
