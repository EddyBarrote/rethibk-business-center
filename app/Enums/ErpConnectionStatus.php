<?php

namespace App\Enums;

enum ErpConnectionStatus: string
{
    case Untested = 'untested';
    case Ok = 'ok';
    case Error = 'error';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Untested => 'Por testar',
            self::Ok => 'Ligado',
            self::Error => 'Com erro',
            self::Disabled => 'Desactivado',
        };
    }
}
