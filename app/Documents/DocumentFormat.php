<?php

namespace App\Documents;

enum DocumentFormat: string
{
    case Docx = 'docx';
    case Pptx = 'pptx';
    case Xlsx = 'xlsx';
    case Pdf = 'pdf';

    public function label(): string
    {
        return match ($this) {
            self::Docx => 'Word',
            self::Pptx => 'PowerPoint',
            self::Xlsx => 'Excel',
            self::Pdf => 'PDF',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Docx => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            self::Pptx => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Pdf => 'application/pdf',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $f) => ['value' => $f->value, 'label' => $f->label()], self::cases());
    }
}
