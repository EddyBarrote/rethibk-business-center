<?php

namespace App\Enums;

enum MailboxStatus: string
{
    case Provisioning = 'provisioning';
    case Active = 'active';
    case Error = 'error';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Provisioning => 'Por configurar',
            self::Active => 'Activa',
            self::Error => 'Com erro',
            self::Disabled => 'Desactivada',
        };
    }
}
