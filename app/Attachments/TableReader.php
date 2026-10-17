<?php

namespace App\Attachments;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * Reads a CSV or Excel file as a table (docs/DECISOES.md, "Contas sobre
 * tabelas anexadas"): the header row and every row after it, so an agent can
 * count and add up the whole file instead of reading it in parts. Values come
 * back as written; number() and date() read the usual Mozambican and
 * international spellings.
 */
final class TableReader
{
    public const EXTENSIONS = ['csv', 'tsv', 'txt', 'xlsx', 'xls', 'ods'];

    private const MAX_ROWS = 200000;

    public static function handles(string $filename, string $mime = ''): bool
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        return in_array($extension, self::EXTENSIONS, true) && ($extension !== 'txt' || str_contains($mime, 'csv'))
            || in_array($mime, ['text/csv', 'text/tab-separated-values', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'], true);
    }

    /**
     * @param  string|int|null  $sheet  name or number (from 1) of an Excel sheet; the first by default
     * @param  int|null  $headerRow  the row (from 1) with the column names; found by default
     * @return array{columns: list<string>, rows: list<list<string>>, sheets: list<string>, header_row: int, plain_numbers: bool}|string the table, or why it cannot be read
     */
    public function read(string $path, string $filename, string|int|null $sheet = null, ?int $headerRow = null): array|string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));

        try {
            [$grid, $sheets] = in_array($extension, ['xlsx', 'xls', 'ods'], true)
                ? $this->spreadsheet($path, $sheet)
                : [$this->csv($path), []];
        } catch (RuntimeException $e) {
            return $e->getMessage();
        } catch (Throwable) {
            return 'Não consegui ler o ficheiro como tabela.';
        }

        // Blank rows are dropped; $lines keeps each row's number in the file.
        $kept = array_filter($grid, fn (array $row) => implode('', array_map('trim', $row)) !== '');
        $lines = array_keys($kept);
        $grid = array_values($kept);

        if ($grid === []) {
            return 'O ficheiro não tem linhas.';
        }

        $index = $headerRow !== null ? (int) (array_search($headerRow - 1, $lines, true) ?: 0) : $this->headerIndex($grid);

        if (! isset($grid[$index])) {
            return 'A tabela só tem '.count($grid).' linhas com dados.';
        }

        $columns = [];
        foreach ($grid[$index] as $i => $name) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
            $name = $name === '' ? 'Coluna '.($i + 1) : $name;
            $base = $name;
            for ($n = 2; in_array($name, $columns, true); $n++) {
                $name = "{$base} ({$n})";
            }
            $columns[] = $name;
        }

        $width = count($columns);
        $rows = array_map(
            fn (array $row) => array_map(fn ($v) => trim((string) $v), array_pad(array_slice($row, 0, $width), $width, '')),
            array_slice($grid, $index + 1, self::MAX_ROWS),
        );

        // Spreadsheet numbers come as numbers (12.5), not as someone wrote them.
        return ['columns' => $columns, 'rows' => $rows, 'sheets' => $sheets, 'header_row' => $lines[$index] + 1, 'plain_numbers' => $sheets !== []];
    }

    /**
     * A number as people write it in a cell: 1 000,50 · 1.000,50 · 1,000.50 · 20,00 · 150 MZN · (30) · -30.
     * $plain: the cell holds a number as the spreadsheet stores it (12.345 is twelve).
     */
    public static function number(string $value, bool $plain = false): ?float
    {
        if ($plain && is_numeric(trim($value))) {
            return (float) trim($value);
        }

        $v = trim(str_replace(["\u{00A0}", "\u{202F}", ' '], '', $value));
        $negative = (bool) preg_match('/^\(.*\)$/', $v) || str_starts_with($v, '-');
        $v = (string) preg_replace('/[^\d.,]/', '', $v);

        if ($v === '' || ! preg_match('/\d/', $v)) {
            return null;
        }

        $comma = strrpos($v, ',');
        $dot = strrpos($v, '.');

        if ($comma !== false && $dot !== false) {
            // The last separator is the decimal one.
            $v = $comma > $dot ? str_replace(['.', ','], ['', '.'], $v) : str_replace(',', '', $v);
        } elseif ($comma !== false) {
            $v = substr_count($v, ',') === 1 && strlen($v) - $comma - 1 !== 3 ? str_replace(',', '.', $v) : str_replace(',', '', $v);
        } elseif ($dot !== false && (substr_count($v, '.') > 1 || strlen($v) - $dot - 1 === 3 && $dot <= 3 && ! str_starts_with($v, '0'))) {
            $v = str_replace('.', '', $v);
        }

        return is_numeric($v) ? ($negative ? -1 : 1) * (float) $v : null;
    }

    /**
     * The day of a cell, as Y-m-d: 30/09/2026 23:45 · 2026-09-30 · 30-09-2026 · an Excel serial.
     */
    public static function date(string $value): ?string
    {
        $v = trim($value);

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $v) && (float) $v > 20000 && (float) $v < 80000) {
            return ExcelDate::excelToDateTimeObject((float) $v)->format('Y-m-d');
        } else {
            return null;
        }

        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * @return list<list<string>>
     */
    private function csv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $first = strtok($content, "\n") ?: '';
        $delimiter = collect([',', ';', "\t", '|'])->sortByDesc(fn ($d) => substr_count($first, $d))->first();

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $grid = [];

        while (($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false && count($grid) <= self::MAX_ROWS + 50) {
            $grid[] = array_map(fn ($v) => $v === 'NULL' ? '' : (string) $v, $row);
        }

        fclose($handle);

        return $grid;
    }

    /**
     * @return array{0: list<list<string>>, 1: list<string>}
     */
    private function spreadsheet(string $path, string|int|null $sheet): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $names = $book->getSheetNames();

        if ($sheet === null || $sheet === '') {
            $worksheet = $book->getSheet(0);
        } elseif (is_numeric($sheet) && isset($names[(int) $sheet - 1])) {
            $worksheet = $book->getSheet((int) $sheet - 1);
        } else {
            $match = collect($names)->search(fn ($n) => Str::lower(Str::ascii($n)) === Str::lower(Str::ascii((string) $sheet)));
            $worksheet = $match === false ? throw new RuntimeException('Folha «'.$sheet.'» não existe. Folhas: '.implode(', ', $names).'.') : $book->getSheet($match);
        }

        $grid = array_map(
            fn (array $row) => array_map(fn ($v) => is_float($v) ? rtrim(rtrim(number_format($v, 6, '.', ''), '0'), '.') : (string) $v, $row),
            $worksheet->toArray(null, true, false, false),
        );

        return [$grid, $names];
    }

    /**
     * The header is the first row that is about as wide as the table and
     * mostly text (titles and blank rows above it are skipped).
     *
     * @param  list<list<string>>  $grid
     */
    private function headerIndex(array $grid): int
    {
        $filled = fn (array $row) => count(array_filter($row, fn ($v) => trim((string) $v) !== ''));
        $sample = array_slice($grid, 0, 20);
        $widest = max(array_map($filled, $sample));

        foreach ($sample as $i => $row) {
            $text = count(array_filter($row, fn ($v) => trim((string) $v) !== '' && self::number((string) $v) === null));

            if ($filled($row) >= max(1, (int) ceil($widest * 0.6)) && $text >= $filled($row) / 2) {
                return $i;
            }
        }

        return 0;
    }
}
