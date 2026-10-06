<?php

namespace App\Enums;

enum AgentStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Active => 'Activo',
            self::Suspended => 'Suspenso',
        };
    }
}
