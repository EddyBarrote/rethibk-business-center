<?php

namespace App\Documents\Renderers;

use App\Documents\Brand;
use App\Documents\DocumentSpec;
use App\Documents\MarkdownBlocks;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Excel through PhpSpreadsheet: every Markdown table becomes a sheet named
 * after the heading above it, numbers become numbers (1 200,50 or 1,200.50
 * or 12%), and the header row carries the brand. Text outside tables goes
 * to a "Notas" sheet.
 */
final class XlsxRenderer implements Renderer
{
    public function render(DocumentSpec $spec, Brand $brand, string $path): void
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle($spec->title)->setCreator($brand->name)->setCompany($brand->name);
        $book->removeSheetByIndex(0);

        $heading = null;
        $notesHeading = null;
        $notes = [];
        $used = [];

        foreach (MarkdownBlocks::parse($spec->markdown) as $block) {
            if ($block['type'] === 'heading') {
                $heading = $notesHeading = $block['text'];

                continue;
            }

            if ($block['type'] === 'table') {
                $this->sheet($book, $this->sheetName($heading ?? $spec->title, $used), $block['header'], $block['rows'], $brand);
                $notesHeading = null;

                continue;
            }

            $lines = match ($block['type']) {
                'paragraph' => [$block['text']],
                'list' => array_map(fn (string $item) => '• '.$item, $block['items']),
                default => [],
            };

            if ($lines !== [] && $notesHeading !== null && $notesHeading !== $spec->title) {
                $notes[] = $notesHeading;
            }

            $notesHeading = $lines !== [] ? null : $notesHeading;
            array_push($notes, ...$lines);
        }

        if ($book->getSheetCount() === 0 || $notes !== []) {
            $sheet = $book->createSheet()->setTitle($this->sheetName($book->getSheetCount() === 0 ? $spec->title : 'Notas', $used));
            $sheet->setCellValue('A1', $spec->title);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($brand->hex());

            foreach ($notes as $row => $line) {
                $sheet->setCellValue('A'.($row + 3), $line);
            }

            $sheet->getColumnDimension('A')->setWidth(100);
            $sheet->getStyle('A:A')->getAlignment()->setWrapText(true);
        }

        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<string>>  $rows
     */
    private function sheet(Spreadsheet $book, string $name, array $header, array $rows, Brand $brand): void
    {
        $sheet = $book->createSheet()->setTitle($name);
        $columns = max(count($header), ...array_map('count', $rows ?: [[]]));

        foreach ($header as $c => $value) {
            $sheet->setCellValue([$c + 1, 1], $value);
        }

        foreach ($rows as $r => $row) {
            foreach ($row as $c => $value) {
                $this->cell($sheet, $c + 1, $r + 2, $value);
            }
        }

        if ($columns === 0) {
            return;
        }

        $last = Coordinate::stringFromColumnIndex($columns);
        $headerStyle = $sheet->getStyle("A1:{$last}1");
        $headerStyle->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $headerStyle->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($brand->hex());
        $sheet->freezePane('A2');
        $sheet->setAutoFilter("A1:{$last}".max(1, count($rows) + 1));

        for ($c = 1; $c <= $columns; $c++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($c))->setAutoSize(true);
        }
    }

    private function cell(Worksheet $sheet, int $column, int $row, string $value): void
    {
        $number = self::number($value);

        if ($number === null) {
            $sheet->setCellValue([$column, $row], $value);

            return;
        }

        $sheet->setCellValue([$column, $row], $number['value']);
        $sheet->getStyle([$column, $row])->getNumberFormat()->setFormatCode($number['format']);
    }

    /**
     * @return array{value: float|int, format: string}|null
     */
    public static function number(string $value): ?array
    {
        $raw = trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $value));
        $percent = str_ends_with($raw, '%');
        $clean = trim((string) preg_replace('/^(MZN|MT|USD|EUR|ZAR|\$|€)\s*|\s*(MZN|MT|USD|EUR|ZAR|%)$/iu', '', $raw));

        $normalised = match (true) {
            (bool) preg_match('/^-?\d{1,3}( \d{3})+(,\d+)?$/', $clean) => str_replace([' ', ','], ['', '.'], $clean),
            (bool) preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $clean) => str_replace(['.', ','], ['', '.'], $clean),
            (bool) preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $clean) => str_replace(',', '', $clean),
            (bool) preg_match('/^-?\d+,\d+$/', $clean) => str_replace(',', '.', $clean),
            (bool) preg_match('/^-?\d+(\.\d+)?$/', $clean) => $clean,
            default => null,
        };

        if ($normalised === null) {
            return null;
        }

        // Codes such as NUIT or phone numbers with leading zeros stay text.
        if (preg_match('/^0\d/', $normalised)) {
            return null;
        }

        $number = str_contains($normalised, '.') ? (float) $normalised : (int) $normalised;
        $decimals = str_contains($normalised, '.') ? '.00' : '';

        return $percent
            ? ['value' => $number / 100, 'format' => '0'.($decimals !== '' ? '.0' : '').'%']
            : ['value' => $number, 'format' => '#,##0'.$decimals];
    }

    /**
     * Excel sheet names: at most 31 characters, no []:*?/\ and unique.
     *
     * @param  array<string, true>  $used
     */
    private function sheetName(string $name, array &$used): string
    {
        $base = mb_substr(trim((string) preg_replace('#[\[\]:*?/\\\\]#u', ' ', $name)) ?: 'Folha', 0, 28);
        $candidate = $base;

        for ($n = 2; isset($used[mb_strtolower($candidate)]); $n++) {
            $candidate = $base.' '.$n;
        }

        $used[mb_strtolower($candidate)] = true;

        return $candidate;
    }
}
