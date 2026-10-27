<?php

namespace App\Ai\Autonomy;

use App\Enums\AutonomyLevel;
use App\Models\ApprovalRule;

final readonly class GateDecision
{
    public function __construct(
        public bool $allowed,
        public AutonomyLevel $requiredLevel,
        public ?string $ceilingReason = null,
        /** The standing rule that decided (docs/DECISOES.md, "Regras de aprovação"). */
        public ?ApprovalRule $rule = null,
        /** Refused by a rule: not even a person is asked. */
        public bool $denied = false,
    ) {}

    public function reason(): string
    {
        if ($this->allowed) {
            return $this->rule !== null ? "Permitido pela regra: {$this->rule->describe()}." : 'Permitido.';
        }

        if ($this->ceilingReason !== null) {
            return "Tecto absoluto: {$this->ceilingReason}.";
        }

        return $this->rule !== null
            ? "Regra: {$this->rule->describe()}."
            : "Exige nível {$this->requiredLevel->code()} para executar sem aprovação.";
    }
}
