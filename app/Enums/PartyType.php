<?php

namespace App\Enums;

enum PartyType: string
{
    case Client = 'client';
    case Supplier = 'supplier';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Client => 'Cliente',
            self::Supplier => 'Fornecedor',
            self::Other => 'Outro',
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
