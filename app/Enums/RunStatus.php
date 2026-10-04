<?php

namespace App\Enums;

enum RunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case AwaitingApproval = 'awaiting_approval';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Em fila',
            self::Running => 'A correr',
            self::AwaitingApproval => 'À espera de aprovação',
            self::Completed => 'Concluído',
            self::Failed => 'Falhou',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Cancelled], true);
    }
}
