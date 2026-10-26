<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Attachments\TableReader;
use App\Models\EmailAttachment;
use App\Models\MessageAttachment;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Counts, adds up and groups a CSV or Excel file sent in this conversation
 * (docs/DECISOES.md, "Contas sobre tabelas anexadas"). The platform goes
 * through every row, so a total never depends on the parts of the file the
 * agent happened to read. Nothing is run: the agent says which columns,
 * filters and groups, and gets the numbers back.
 */
final class AnalyzeAttachment extends LocalCapability
{
    private const MAX_GROUPS = 150;

    private const OPS = ['=', '!=', 'contains', 'not_contains', 'empty', 'not_empty', '>', '>=', '<', '<='];

    public function __construct(private readonly TableReader $reader) {}

    public function key(): string
    {
        return 'files.analyze';
    }

    public function name(): string
    {
        return 'Fazer contas a uma tabela anexada';
    }

    public function description(): string
    {
        return 'Conta linhas, soma colunas e agrupa um anexo CSV ou Excel desta conversa ou de um email dela, percorrendo o ficheiro todo. '
            .'Usa-o para qualquer total, contagem ou quebra (por dia, por método, por pessoa) em vez de ler a tabela por partes com files.read: '
            .'contas feitas sobre partes estão erradas. Sem filtros nem grupos, devolve as colunas, quantas linhas há e os valores que cada coluna toma. '
            .'Filtra com where (coluna, operador, valor) e com date_column + from/to (datas AAAA-MM-DD, inclusive); agrupa com group_by (até 2 colunas, '
            .'ou date_column com group_by_day); soma com sum. Os nomes das colunas não distinguem maiúsculas nem acentos.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'attachment_id' => $schema->integer()->description('Anexo desta conversa.'),
            'email_attachment_id' => $schema->integer()->description('Em vez de attachment_id: anexo de um email desta conversa (o número que email.read mostra em «anexo #»).'),
            'sheet' => $schema->string()->description('Folha do Excel (nome ou número a partir de 1). Por defeito, a primeira.'),
            'header_row' => $schema->integer()->min(1)->description('Linha com os nomes das colunas, se não for encontrada sozinha.'),
            'where' => $schema->array()->items($schema->object([
                'column' => $schema->string()->required(),
                'op' => $schema->string()->enum(self::OPS)->required(),
                'value' => $schema->string(),
            ]))->description('Filtros, todos aplicados (E). = e != comparam o texto sem maiúsculas nem acentos; > >= < <= comparam números.'),
            'date_column' => $schema->string()->description('Coluna de data para from/to e group_by_day.'),
            'from' => $schema->string()->description('Primeiro dia, AAAA-MM-DD.'),
            'to' => $schema->string()->description('Último dia, AAAA-MM-DD (inclusive).'),
            'group_by' => $schema->array()->items($schema->string())->description('Até 2 colunas para agrupar.'),
            'group_by_day' => $schema->boolean()->description('Agrupar também pelo dia da date_column.'),
            'sum' => $schema->array()->items($schema->string())->description('Colunas numéricas a somar (aceita 1 000,50 · 1.000,50 · 1,000.50).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'attachment_id' => 'required_without:email_attachment_id|nullable|integer',
            'email_attachment_id' => 'nullable|integer',
            'sheet' => 'nullable',
            'header_row' => 'nullable|integer|min:1',
            'where' => 'nullable|array|max:10',
            'where.*.column' => 'required|string',
            'where.*.op' => 'required|in:'.implode(',', self::OPS),
            'where.*.value' => 'nullable',
            'date_column' => 'nullable|string',
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d',
            'group_by' => 'nullable|array|max:2',
            'group_by.*' => 'string',
            'group_by_day' => 'nullable|boolean',
            'sum' => 'nullable|array|max:6',
            'sum.*' => 'string',
        ])->validate();

        $attachment = match (true) {
            isset($data['email_attachment_id']) => EmailAttachment::query()
                ->whereIn('email_message_id', app(TaskThread::class)->readableEmailIds($context->run))
                ->find($data['email_attachment_id']),
            $context->run->task_id === null => null,
            default => MessageAttachment::query()
                ->ready()
                ->whereIn('task_id', app(TaskThread::class)->readableTaskIds($context->run))
                ->whereNotNull('task_message_id')
                ->find($data['attachment_id']),
        };

        if ($attachment === null) {
            return CapabilityResult::error('Anexo não encontrado nesta conversa.'.(isset($data['email_attachment_id']) ? '' : ' Se é anexo de um email, passa-o em email_attachment_id.'));
        }

        if (! TableReader::handles($attachment->fileName(), $attachment->mimeType())) {
            return CapabilityResult::error("O {$attachment->reference()} ({$attachment->fileName()}) não é uma tabela CSV ou Excel. Lê-o com files.read.");
        }

        $path = $attachment->storagePath();

        if ($path === null || ! Storage::disk($attachment->diskName())->exists($path)) {
            return CapabilityResult::error("O ficheiro do {$attachment->reference()} já não está disponível.");
        }

        $table = $this->reader->read(Storage::disk($attachment->diskName())->path($path), $attachment->fileName(), $data['sheet'] ?? null, $data['header_row'] ?? null);

        if (is_string($table)) {
            return CapabilityResult::error($table);
        }

        $find = function (string $name) use ($table): ?int {
            $wanted = self::normal($name);
            foreach ($table['columns'] as $i => $column) {
                if (self::normal($column) === $wanted) {
                    return $i;
                }
            }

            return null;
        };

        $missing = collect([...array_column($data['where'] ?? [], 'column'), $data['date_column'] ?? null, ...($data['group_by'] ?? []), ...($data['sum'] ?? [])])
            ->filter()->reject(fn ($c) => $find($c) !== null)->unique()->values();

        if ($missing->isNotEmpty()) {
            return CapabilityResult::error('Colunas que não existem: '.$missing->implode(', ').'. As colunas são: '.implode(' · ', $table['columns']).'.');
        }

        if ((isset($data['from']) || isset($data['to']) || ! empty($data['group_by_day'])) && ! isset($data['date_column'])) {
            return CapabilityResult::error('Para from, to ou group_by_day indica a date_column.');
        }

        $head = "{$attachment->reference()} {$attachment->fileName()}".($table['sheets'] !== [] ? ' · folhas: '.implode(', ', $table['sheets']) : '')
            .' · '.count($table['rows'])." linhas de dados (cabeçalho na linha {$table['header_row']})";

        $plain = $table['plain_numbers'];
        $filtering = ($data['where'] ?? []) !== [] || isset($data['from']) || isset($data['to']);
        $grouping = ($data['group_by'] ?? []) !== [] || ! empty($data['group_by_day']);

        if (! $filtering && ! $grouping && ($data['sum'] ?? []) === []) {
            return CapabilityResult::text($head."\n\n".$this->profile($table, $plain));
        }

        $dateIndex = isset($data['date_column']) ? $find($data['date_column']) : null;
        $groupIndexes = array_map($find, $data['group_by'] ?? []);
        $sumIndexes = array_map($find, $data['sum'] ?? []);
        $sumNames = array_map(fn ($i) => $table['columns'][$i], $sumIndexes);
        $filters = array_map(fn ($w) => ['i' => $find($w['column']), 'op' => $w['op'], 'value' => (string) ($w['value'] ?? '')], $data['where'] ?? []);

        $groups = [];
        $total = ['count' => 0, 'sums' => array_fill(0, count($sumIndexes), 0.0), 'blank' => array_fill(0, count($sumIndexes), 0)];
        $noDate = 0;
        $firstDay = null;
        $lastDay = null;

        foreach ($table['rows'] as $row) {
            foreach ($filters as $f) {
                if (! $this->matches($row[$f['i']], $f['op'], $f['value'], $plain)) {
                    continue 2;
                }
            }

            $day = null;
            if ($dateIndex !== null) {
                $day = TableReader::date($row[$dateIndex]);
                if ($day === null) {
                    if (isset($data['from']) || isset($data['to'])) {
                        $noDate++;

                        continue;
                    }
                } elseif (isset($data['from']) && $day < $data['from'] || isset($data['to']) && $day > $data['to']) {
                    continue;
                }
                if ($day !== null) {
                    $firstDay = $firstDay === null || $day < $firstDay ? $day : $firstDay;
                    $lastDay = $lastDay === null || $day > $lastDay ? $day : $lastDay;
                }
            }

            $key = [];
            if (! empty($data['group_by_day'])) {
                $key[] = $day ?? '(sem data)';
            }
            foreach ($groupIndexes as $i) {
                $key[] = $row[$i] === '' ? '(vazio)' : $row[$i];
            }
            $key = implode(' · ', $key);

            $groups[$key] ??= ['count' => 0, 'sums' => array_fill(0, count($sumIndexes), 0.0)];
            $groups[$key]['count']++;
            $total['count']++;

            foreach ($sumIndexes as $n => $i) {
                $value = TableReader::number($row[$i], $plain);
                if ($value === null) {
                    if ($row[$i] !== '') {
                        $total['blank'][$n]++;
                    }

                    continue;
                }
                $groups[$key]['sums'][$n] += $value;
                $total['sums'][$n] += $value;
            }
        }

        $lines = [$head];
        $lines[] = 'Linhas que contam: '.$total['count'].($filtering ? ' (depois dos filtros)' : '')
            .($firstDay !== null ? " · datas de {$firstDay} a {$lastDay}" : '')
            .($noDate > 0 ? " · {$noDate} linhas sem data legível ficaram de fora" : '');

        foreach ($sumNames as $n => $name) {
            $lines[] = "Soma de «{$name}»: ".self::money($total['sums'][$n])
                .($total['blank'][$n] > 0 ? " ({$total['blank'][$n]} valores não numéricos ignorados)" : '');
        }

        if ($grouping) {
            if (! empty($data['group_by_day'])) {
                ksort($groups);
            } else {
                uasort($groups, fn ($a, $b) => $b['count'] <=> $a['count']);
            }

            $label = implode(' · ', [...(! empty($data['group_by_day']) ? ['Dia'] : []), ...array_map(fn ($i) => $table['columns'][$i], $groupIndexes)]);
            $lines[] = '';
            $lines[] = "| {$label} | Linhas |".implode('', array_map(fn ($s) => " Soma {$s} |", $sumNames));
            $lines[] = '|---|---|'.str_repeat('---|', count($sumNames));

            foreach (array_slice($groups, 0, self::MAX_GROUPS, true) as $key => $group) {
                $lines[] = '| '.str_replace('|', '/', (string) $key)." | {$group['count']} |".implode('', array_map(fn ($s) => ' '.self::money($s).' |', $group['sums']));
            }

            if (count($groups) > self::MAX_GROUPS) {
                $lines[] = '('.(count($groups) - self::MAX_GROUPS).' grupos a mais não mostrados; filtra ou agrupa por menos colunas.)';
            }
        }

        return CapabilityResult::text(implode("\n", $lines));
    }

    public function summarise(array $arguments): string
    {
        return isset($arguments['email_attachment_id']) ? 'Contas ao anexo de email #'.$arguments['email_attachment_id'] : 'Contas ao anexo #'.($arguments['attachment_id'] ?? '?');
    }

    /**
     * The columns with a few sample values, and the values of the columns
     * that take few (method, status, origin), with how many rows each.
     *
     * @param  array{columns: list<string>, rows: list<list<string>>}  $table
     */
    private function profile(array $table, bool $plain): string
    {
        $lines = ['Colunas:'];

        foreach ($table['columns'] as $i => $column) {
            $values = array_column($table['rows'], $i);
            $filled = array_values(array_filter($values, fn ($v) => $v !== ''));
            $counts = array_count_values($filled);
            arsort($counts);
            $numeric = $filled !== [] && count(array_filter($filled, fn ($v) => TableReader::number($v, $plain) !== null)) === count($filled);
            $dated = $filled !== [] && TableReader::date($filled[0]) !== null;

            $line = "- {$column}: ".count($filled).' preenchidas';

            if (count($counts) <= 12 && ! $numeric) {
                $line .= ' · valores: '.collect($counts)->map(fn ($n, $v) => "«{$v}» ({$n})")->implode(', ');
            } else {
                $line .= ' · ex.: '.collect(array_slice($filled, 0, 3))->map(fn ($v) => '«'.Str::limit($v, 40).'»')->implode(', ');
                $line .= $numeric ? ' · numérica' : '';
                $line .= $dated ? ' · data' : '';
            }

            $lines[] = $line;
        }

        $lines[] = '';
        $lines[] = 'Para totais, chama de novo com sum, where, date_column/from/to e group_by.';

        return implode("\n", $lines);
    }

    private function matches(string $cell, string $op, string $value, bool $plain): bool
    {
        return match ($op) {
            '=' => self::normal($cell) === self::normal($value),
            '!=' => self::normal($cell) !== self::normal($value),
            'contains' => str_contains(self::normal($cell), self::normal($value)),
            'not_contains' => ! str_contains(self::normal($cell), self::normal($value)),
            'empty' => $cell === '',
            'not_empty' => $cell !== '',
            default => ($a = TableReader::number($cell, $plain)) !== null && ($b = TableReader::number($value)) !== null && match ($op) {
                '>' => $a > $b, '>=' => $a >= $b, '<' => $a < $b, default => $a <= $b,
            },
        };
    }

    private static function normal(string $value): string
    {
        return Str::lower(trim((string) preg_replace('/\s+/u', ' ', Str::ascii($value))));
    }

    private static function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
