<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Todo = 'todo';
    case InProgress = 'in_progress';
    case WaitingHuman = 'waiting_human';
    case InReview = 'in_review';
    case Blocked = 'blocked';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Todo => 'Por fazer',
            self::InProgress => 'Em curso',
            self::WaitingHuman => 'À tua espera',
            self::InReview => 'Em revisão',
            self::Blocked => 'Bloqueada',
            self::Done => 'Feita',
            self::Cancelled => 'Cancelada',
        };
    }

    public function isClosed(): bool
    {
        return $this === self::Done || $this === self::Cancelled;
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! $status->isClosed()));
    }
}
