<?php

namespace App\Documents;

/**
 * Page designs for Word and PDF. Slides always open with a cover slide and
 * spreadsheets have one sheet per table, whatever the template.
 */
enum DocumentTemplate: string
{
    /** Title at the top, brand band in the header. */
    case Document = 'documento';

    /** Cover page with title, organisation and date, then the content. */
    case Report = 'relatorio';

    /** Letterhead: logo and name, date, then the text. */
    case Letter = 'carta';

    public function label(): string
    {
        return match ($this) {
            self::Document => 'Documento',
            self::Report => 'Relatório com capa',
            self::Letter => 'Carta',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $t) => ['value' => $t->value, 'label' => $t->label()], self::cases());
    }
}
