<?php

namespace App\Enums;

enum KnowledgeType: string
{
    case Decision = 'decision';
    case MeetingBrief = 'meeting_brief';
    case Document = 'document';
    case Pattern = 'pattern';
    case EntityNote = 'entity_note';

    public function label(): string
    {
        return match ($this) {
            self::Decision => 'Decisão',
            self::MeetingBrief => 'Resumo de reunião',
            self::Document => 'Documento',
            self::Pattern => 'Padrão',
            self::EntityNote => 'Nota sobre entidade',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
