<?php

namespace App\Enums;

enum GoalStatus: string
{
    case Planned = 'planned';
    case Active = 'active';
    case Achieved = 'achieved';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planeado',
            self::Active => 'Em curso',
            self::Achieved => 'Alcançado',
            self::Cancelled => 'Cancelado',
        };
    }
}
