<?php

namespace App\Enums;

enum TriggerType: string
{
    case Email = 'email';
    case Schedule = 'schedule';
    case Manual = 'manual';
    case Agent = 'agent';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Schedule => 'Rotina',
            self::Manual => 'Manual',
            self::Agent => 'Outro agente',
            self::Webhook => 'Webhook',
        };
    }
}
