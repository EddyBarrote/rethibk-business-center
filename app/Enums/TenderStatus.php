<?php

namespace App\Enums;

enum TenderStatus: string
{
    case New = 'new';
    case Reviewing = 'reviewing';
    case Bidding = 'bidding';
    case Submitted = 'submitted';
    case Won = 'won';
    case Lost = 'lost';
    case Discarded = 'discarded';

    public function label(): string
    {
        return match ($this) {
            self::New => 'Novo',
            self::Reviewing => 'Em análise',
            self::Bidding => 'A preparar proposta',
            self::Submitted => 'Proposta submetida',
            self::Won => 'Ganho',
            self::Lost => 'Perdido',
            self::Discarded => 'Descartado',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
