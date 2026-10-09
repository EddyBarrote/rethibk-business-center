<?php

namespace App\Enums;

/**
 * How a flow starts: with a kind of email after triage, or on the schedules
 * of its routines (docs/DECISOES.md, "Rotinas e fluxos agendados").
 */
enum WorkflowTrigger: string
{
    case Email = 'email';
    case Schedule = 'schedule';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email depois da triagem',
            self::Schedule => 'Por agenda',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $trigger) => ['value' => $trigger->value, 'label' => $trigger->label()], self::cases());
    }
}
