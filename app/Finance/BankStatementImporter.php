<?php

namespace App\Finance;

use App\Enums\BankTransactionStatus;
use App\Models\BankStatement;
use App\Models\BankTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Reads bank statements exported as CSV (the format every Mozambican bank's
 * online banking offers) and records their lines once: a line already
 * imported, from this file or an earlier one, is skipped.
 */
final class BankStatementImporter
{
    private const COLUMNS = [
        'date' => ['data', 'date', 'data valor', 'data mov', 'data movimento', 'data lancamento', 'data operacao', 'value date', 'booking date'],
        'description' => ['descricao', 'descritivo', 'description', 'movimento', 'historico', 'detalhes', 'narrativa', 'details'],
        'reference' => ['referencia', 'ref', 'reference', 'documento', 'n documento', 'cheque'],
        'debit' => ['debito', 'debit', 'saida', 'saidas', 'levantamento'],
        'credit' => ['credito', 'credit', 'entrada', 'entradas', 'deposito'],
        'amount' => ['montante', 'valor', 'amount', 'importancia', 'quantia'],
        'balance' => ['saldo', 'balance', 'saldo contabilistico', 'saldo disponivel'],
    ];

    /**
     * @param  array{account_name: string, bank?: string|null, source: string, original_name?: string|null, path?: string|null, email_attachment_id?: int|null, uploaded_by_user_id?: int|null}  $meta
     * @return array{statement: BankStatement, imported: int, skipped: int}
     *
     * @throws InvalidArgumentException when the file is not a readable statement
     */
    public function importCsv(string $contents, array $meta): array
    {
        $rows = $this->rows($contents);
        [$headerIndex, $map] = $this->header($rows);
        $lines = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $line = $this->line($row, $map);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            throw new InvalidArgumentException('O extracto não tem movimentos legíveis.');
        }

        return $this->record($lines, $meta);
    }

    /**
     * Lines read by an agent from a PDF or spreadsheet statement.
     *
     * @param  list<array{date: string, description: string, amount: float|int|string, reference?: string|null, balance?: float|int|string|null}>  $lines
     * @param  array{account_name: string, bank?: string|null, source: string, original_name?: string|null, path?: string|null, email_attachment_id?: int|null, uploaded_by_user_id?: int|null}  $meta
     * @return array{statement: BankStatement, imported: int, skipped: int}
     */
    public function importLines(array $lines, array $meta): array
    {
        $parsed = [];

        foreach ($lines as $line) {
            $date = $this->date((string) $line['date']);
            $amount = $this->amount((string) $line['amount']);

            if ($date === null || $amount === null) {
                throw new InvalidArgumentException("Movimento ilegível: {$line['date']} {$line['amount']}");
            }

            $parsed[] = [
                'booked_at' => $date,
                'description' => Str::limit(trim($line['description']), 495),
                'reference' => filled($line['reference'] ?? null) ? (string) $line['reference'] : null,
                'amount' => $amount,
                'balance' => isset($line['balance']) ? $this->amount((string) $line['balance']) : null,
            ];
        }

        return $this->record($parsed, $meta);
    }

    /**
     * @param  list<array{booked_at: Carbon, description: string, reference: string|null, amount: float, balance: float|null}>  $lines
     * @param  array<string, mixed>  $meta
     * @return array{statement: BankStatement, imported: int, skipped: int}
     */
    private function record(array $lines, array $meta): array
    {
        return DB::transaction(function () use ($lines, $meta) {
            $dates = array_map(fn (array $l) => $l['booked_at'], $lines);
            $statement = BankStatement::query()->create([
                ...$meta,
                'currency' => 'MZN',
                'period_start' => min($dates),
                'period_end' => max($dates),
                'closing_balance' => end($lines)['balance'],
            ]);

            $imported = 0;
            $skipped = 0;
            $seen = [];

            foreach ($lines as $line) {
                $base = implode('|', [$meta['account_name'], $line['booked_at']->toDateString(), number_format($line['amount'], 2, '.', ''), Str::lower($line['description']), $line['reference']]);
                $seen[$base] = ($seen[$base] ?? 0) + 1;
                $fingerprint = hash('sha256', $base.'|'.$seen[$base]);

                if (BankTransaction::query()->where('fingerprint', $fingerprint)->exists()) {
                    $skipped++;

                    continue;
                }

                $statement->transactions()->create([...$line, 'fingerprint' => $fingerprint, 'status' => BankTransactionStatus::Unmatched]);
                $imported++;
            }

            $statement->forceFill(['transaction_count' => $imported])->save();

            return ['statement' => $statement, 'imported' => $imported, 'skipped' => $skipped];
        });
    }

    /**
     * @return list<list<string>>
     */
    private function rows(string $contents): array
    {
        $contents = mb_convert_encoding($contents, 'UTF-8', mb_detect_encoding($contents, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'UTF-8');
        $contents = (string) preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        $lines = preg_split('/\R/u', trim($contents)) ?: [];
        $sample = implode("\n", array_slice($lines, 0, 10));
        $delimiter = collect([';', ',', "\t", '|'])->sortByDesc(fn (string $d) => substr_count($sample, $d))->first();

        return array_values(array_map(fn (string $line) => array_map('trim', str_getcsv($line, (string) $delimiter, '"', '')), array_filter($lines, fn (string $l) => trim($l) !== '')));
    }

    /**
     * The first row naming a date column and an amount (or debit/credit).
     *
     * @param  list<list<string>>  $rows
     * @return array{0: int, 1: array<string, int>}
     */
    private function header(array $rows): array
    {
        foreach (array_slice($rows, 0, 30, true) as $index => $row) {
            $map = [];

            foreach ($row as $column => $label) {
                $label = trim((string) preg_replace('/[^a-z ]/', ' ', Str::lower(Str::ascii($label))));
                $label = (string) preg_replace('/\s+/', ' ', $label);

                foreach (self::COLUMNS as $field => $names) {
                    if (! isset($map[$field]) && in_array($label, $names, true)) {
                        $map[$field] = $column;
                    }
                }
            }

            if (isset($map['date'], $map['description']) && (isset($map['amount']) || isset($map['debit']) || isset($map['credit']))) {
                return [$index, $map];
            }
        }

        throw new InvalidArgumentException('Não encontrei o cabeçalho do extracto (data, descrição e montante ou débito/crédito).');
    }

    /**
     * @param  list<string>  $row
     * @param  array<string, int>  $map
     * @return array{booked_at: Carbon, description: string, reference: string|null, amount: float, balance: float|null}|null
     */
    private function line(array $row, array $map): ?array
    {
        $date = $this->date($row[$map['date']] ?? '');

        if ($date === null) {
            return null;
        }

        if (isset($map['amount'])) {
            $amount = $this->amount($row[$map['amount']] ?? '');
        } else {
            $credit = $this->amount($row[$map['credit'] ?? -1] ?? '') ?? 0.0;
            $debit = $this->amount($row[$map['debit'] ?? -1] ?? '') ?? 0.0;
            $amount = ($credit === 0.0 && $debit === 0.0) ? null : round(abs($credit) - abs($debit), 2);
        }

        if ($amount === null) {
            return null;
        }

        return [
            'booked_at' => $date,
            'description' => Str::limit($row[$map['description']] ?? '', 495),
            'reference' => isset($map['reference']) && filled($row[$map['reference']] ?? null) ? $row[$map['reference']] : null,
            'amount' => $amount,
            'balance' => isset($map['balance']) ? $this->amount($row[$map['balance']] ?? '') : null,
        ];
    }

    private function date(string $value): ?Carbon
    {
        $value = trim($value);

        foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/y', 'Y/m/d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }

            if ($date !== null && $date->format($format) === $value) {
                return $date;
            }
        }

        return null;
    }

    /**
     * "1.234,56", "1,234.56", "-1234.56", "1 234,56 MZN", "(500,00)".
     */
    public function amount(string $value): ?float
    {
        $value = trim($value);
        $negative = str_starts_with($value, '-') || (str_starts_with($value, '(') && str_ends_with($value, ')')) || Str::endsWith(Str::upper($value), ' D');
        $digits = (string) preg_replace('/[^0-9.,]/', '', $value);

        if ($digits === '' || ! preg_match('/\d/', $digits)) {
            return null;
        }

        $lastComma = strrpos($digits, ',');
        $lastDot = strrpos($digits, '.');

        if ($lastComma !== false && ($lastDot === false || $lastComma > $lastDot)) {
            $digits = str_replace(['.', ','], ['', '.'], $digits);
        } else {
            $digits = str_replace(',', '', $digits);
        }

        $number = round((float) $digits, 2);

        return $negative ? -$number : $number;
    }
}
