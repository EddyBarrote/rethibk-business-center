<?php

namespace App\Enums;

/**
 * Where a run of a flow is: an agent working on a step (running), waiting for
 * a person, an approval or a timer (waiting), stuck on a step nobody could do
 * (blocked), or finished.
 */
enum WorkflowRunStatus: string
{
    case Running = 'running';
    case Waiting = 'waiting';
    case Blocked = 'blocked';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Em curso',
            self::Waiting => 'À espera',
            self::Blocked => 'Bloqueado',
            self::Completed => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Running, self::Waiting, self::Blocked], true);
    }
}
