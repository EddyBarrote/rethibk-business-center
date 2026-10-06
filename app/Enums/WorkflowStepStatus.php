<?php

namespace App\Enums;

enum WorkflowStepStatus: string
{
    /** An agent is working on it. */
    case Active = 'active';
    /** The agent asked for an approval and waits for the decision. */
    case WaitingApproval = 'waiting_approval';
    /** A person, another agent or a timer has it. */
    case Waiting = 'waiting';
    case Done = 'done';
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Em curso',
            self::WaitingApproval => 'À espera de aprovação',
            self::Waiting => 'À espera',
            self::Done => 'Feito',
            self::Blocked => 'Bloqueado',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Active, self::WaitingApproval, self::Waiting], true);
    }
}
