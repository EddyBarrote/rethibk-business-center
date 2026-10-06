<?php

namespace App\Enums;

enum BriefingType: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
    case Meeting = 'meeting';
    case Adhoc = 'adhoc';

    public function label(): string
    {
        return match ($this) {
            self::Daily => 'Diário',
            self::Weekly => 'Semanal',
            self::Meeting => 'Pré-reunião',
            self::Adhoc => 'Pontual',
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
