<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planeado',
            self::Active => 'Em curso',
            self::Done => 'Concluído',
            self::Cancelled => 'Cancelado',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Planned, self::Active], true);
    }
}
