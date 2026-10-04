<?php

namespace App\Enums;

enum ContractStatus: string
{
    case Active = 'active';
    case Renewing = 'renewing';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Activo',
            self::Renewing => 'Em renovação',
            self::Ended => 'Terminado',
            self::Cancelled => 'Cancelado',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
