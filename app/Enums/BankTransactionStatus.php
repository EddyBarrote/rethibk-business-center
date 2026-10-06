<?php

namespace App\Enums;

enum BankTransactionStatus: string
{
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';
    case Reconciled = 'reconciled';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Unmatched => 'Por reconciliar',
            self::Suggested => 'Sugestão do agente',
            self::Reconciled => 'Reconciliado',
            self::Ignored => 'Ignorado',
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
