<?php

namespace App\Sales;

use App\Erp\ErpCallResult;
use App\Erp\ErpDialect;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Models\ErpSale;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Past sales an agent can learn from when it prepares a proposal
 * (docs/DECISOES.md, "Vendas parecidas"). Perfex finds invoices and quotes by
 * number or client only, and a document's lines cost one read each, so the
 * platform keeps a copy (erp_sales) and searches what was sold in it.
 */
final class SalesHistory
{
    private const PAGE = 100;

    private const MAX_PAGES = 20;

    /** Documents read in full per sync; the rest wait for the next one. */
    private const MAX_DETAILS = 400;

    /** Words that say nothing about what was sold. */
    private const STOPWORDS = ['para', 'com', 'por', 'uma', 'uns', 'umas', 'dos', 'das', 'nos', 'nas', 'que', 'sobre', 'evento', 'eventos', 'cotacao', 'cotacoes', 'proposta', 'factura', 'facturas', 'fatura', 'faturas', 'servico', 'servicos', 'and', 'the', 'for'];

    /**
     * @param  (Closure(string, array<string, mixed>): ErpCallResult)|null  $native  a Perfex read, for tests; by default through the gateway
     */
    public function __construct(private readonly ErpGateway $erp, private readonly ?Closure $native = null) {}

    /**
     * Brings the copy up to date: lists every invoice, quote and catalogue
     * item, and reads in full the documents that are new or changed.
     *
     * @return array{invoice: int, estimate: int, item: int, read: int, removed: int}
     */
    public function sync(): array
    {
        if ($this->native === null && $this->erp->dialect() !== ErpDialect::Perfex) {
            throw new ErpException('Este ERP não dá o histórico de vendas.');
        }

        $counts = ['invoice' => 0, 'estimate' => 0, 'item' => 0, 'read' => 0, 'removed' => 0];
        $budget = self::MAX_DETAILS;

        foreach ([ErpSale::INVOICE => ['list_invoices', 'get_invoice'], ErpSale::ESTIMATE => ['list_estimates', 'get_estimate']] as $kind => [$list, $get]) {
            $seen = [];
            $known = ErpSale::query()->where('kind', $kind)->pluck('signature', 'erp_id');

            foreach ($this->all($list) as $row) {
                $id = (string) ($row['id'] ?? '');

                if ($id === '') {
                    continue;
                }

                $seen[] = $id;
                $counts[$kind]++;
                $signature = md5(implode('|', [$row['status'] ?? '', $row['total'] ?? '', $row['date'] ?? '', $row['customer'] ?? '', $row['formatted_number'] ?? '']));

                if (($known[$id] ?? null) === $signature || $budget <= 0) {
                    continue;
                }

                $budget--;
                $counts['read']++;
                $this->keepDocument($kind, $row, $this->read($get, ['id' => (int) $id]), $signature);
            }

            $counts['removed'] += ErpSale::query()->where('kind', $kind)->whereNotIn('erp_id', $seen)->delete();
        }

        $seen = [];

        foreach ($this->all('list_items') as $row) {
            $id = (string) ($row['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $seen[] = $id;
            $counts['item']++;
            $line = $this->line($row);

            ErpSale::query()->updateOrCreate(['kind' => ErpSale::ITEM, 'erp_id' => $id], [
                'number' => null,
                'customer' => null,
                'date' => null,
                'status' => null,
                'currency' => null,
                'total' => $line['rate'],
                'lines' => [[...$line, 'unit' => $row['unit'] ?? null, 'tax' => $row['taxrate'] ?? null, 'group' => $row['group_name'] ?? null]],
                'note' => null,
                'search_text' => $this->searchable([$line['description'], $line['long_description'], $row['group_name'] ?? null]),
                'signature' => md5((string) json_encode($row)),
            ]);
        }

        $counts['removed'] += ErpSale::query()->where('kind', ErpSale::ITEM)->whereNotIn('erp_id', $seen)->delete();

        return $counts;
    }

    public function lastSyncedAt(): ?Carbon
    {
        $at = ErpSale::query()->max('updated_at');

        return $at === null ? null : Carbon::parse($at);
    }

    /**
     * Catalogue items and documents that sold what the query names, best
     * match first, then the most recent.
     *
     * @param  'all'|'invoice'|'estimate'  $kind
     * @return array{catalogue: list<array<string, mixed>>, documents: list<array<string, mixed>>}
     */
    public function search(string $query, string $kind = 'all', ?string $customer = null, int $limit = 8): array
    {
        $terms = $this->terms($query);

        if ($terms === []) {
            return ['catalogue' => [], 'documents' => []];
        }

        $matches = fn (string $type) => ErpSale::query()
            ->where('kind', $type)
            ->where(function ($q) use ($terms): void {
                foreach ($terms as $term) {
                    $q->orWhere('search_text', 'like', '%'.$term.'%');
                }
            })
            ->when($customer !== null && $customer !== '', fn ($q) => $q->where('customer', 'like', '%'.$customer.'%'))
            ->limit(300)
            ->get();

        $rank = fn (Collection $rows) => $rows
            ->map(fn (ErpSale $sale) => [$sale, collect($terms)->filter(fn (string $t) => str_contains((string) $sale->search_text, $t))->count()])
            ->sortBy([[1, 'desc'], fn (array $a, array $b) => ($b[0]->date->timestamp ?? 0) <=> ($a[0]->date->timestamp ?? 0)])
            ->values();

        $types = $kind === 'all' ? [ErpSale::INVOICE, ErpSale::ESTIMATE] : [$kind];
        $documents = $rank(collect($types)->flatMap(fn (string $type) => $matches($type)))->take(max(1, min($limit, 20)));

        return [
            'catalogue' => $customer === null || $customer === ''
                ? $rank($matches(ErpSale::ITEM))->take(10)->map(fn (array $hit) => $this->catalogueItem($hit[0]))->all()
                : [],
            'documents' => $documents->map(fn (array $hit) => $this->document($hit[0], $terms))->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $full
     */
    private function keepDocument(string $kind, array $row, array $full, string $signature): void
    {
        $lines = array_map(fn (array $item) => $this->line($item), array_values(array_filter((array) ($full['items'] ?? []), 'is_array')));
        $note = trim(implode("\n", array_filter([trim((string) ($full['clientnote'] ?? '')), trim((string) ($full['terms'] ?? ''))])));
        $discount = (float) ($full['discount_total'] ?? 0);

        ErpSale::query()->updateOrCreate(['kind' => $kind, 'erp_id' => (string) $row['id']], [
            'number' => $row['formatted_number'] ?? null,
            'customer' => isset($row['customer']) ? trim((string) $row['customer']) : null,
            'customer_id' => isset($row['customer_id']) ? (string) $row['customer_id'] : null,
            'date' => $row['date'] ?? null,
            'status' => $row['status_label'] ?? (isset($row['status']) ? (string) $row['status'] : null),
            'currency' => $row['currency'] ?? ($full['currency_name'] ?? null),
            'total' => $row['total'] ?? null,
            'lines' => $lines,
            'note' => trim($note.($discount > 0 ? "\nDesconto: ".number_format($discount, 2, '.', '') : '')) ?: null,
            'search_text' => $this->searchable([...array_column($lines, 'description'), ...array_column($lines, 'long_description'), $note]),
            'signature' => $signature,
        ]);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{description: string, long_description: string|null, qty: float|null, rate: float|null, amount: float|null, taxes: list<string>}
     */
    private function line(array $item): array
    {
        $taxes = array_map(fn ($t) => is_array($t) ? trim(($t['name'] ?? '').' '.($t['rate'] ?? '').'%') : (string) $t, (array) ($item['taxes'] ?? []));

        return [
            'description' => trim((string) ($item['description'] ?? '')),
            'long_description' => ($long = trim(str_replace("\r", '', (string) ($item['long_description'] ?? '')))) === '' ? null : $long,
            'qty' => isset($item['qty']) ? (float) $item['qty'] : null,
            'rate' => isset($item['rate']) ? (float) $item['rate'] : null,
            'amount' => isset($item['amount']) ? (float) $item['amount'] : null,
            'taxes' => array_values(array_filter($taxes)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function catalogueItem(ErpSale $sale): array
    {
        $line = (array) ($sale->lines[0] ?? []);

        return [
            'erp_item_id' => $sale->erp_id,
            'description' => $line['description'] ?? null,
            'long_description' => $line['long_description'] ?? null,
            'rate' => $line['rate'] ?? null,
            'unit' => $line['unit'] ?? null,
            'tax' => isset($line['tax']) ? $line['tax'].'%' : null,
        ];
    }

    /**
     * @param  list<string>  $terms
     * @return array<string, mixed>
     */
    private function document(ErpSale $sale, array $terms): array
    {
        return [
            'type' => $sale->kind === ErpSale::INVOICE ? 'factura' : 'cotação',
            'number' => $sale->number,
            'client' => $sale->customer,
            'date' => $sale->date?->toDateString(),
            'status' => $sale->status,
            'total' => $sale->total === null ? null : (float) $sale->total,
            'currency' => $sale->currency,
            'lines' => array_map(fn (array $line) => [
                ...array_intersect_key($line, array_flip(['description', 'long_description', 'qty', 'rate', 'amount', 'taxes'])),
                'matches' => collect($terms)->contains(fn (string $t) => str_contains($this->searchable([$line['description'] ?? '', $line['long_description'] ?? '']), $t)),
            ], (array) $sale->lines),
            'note' => $sale->note,
        ];
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', $this->searchable([$query])) ?: [])
            ->filter(fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, self::STOPWORDS, true))
            ->unique()->take(8)->values()->all();
    }

    /**
     * @param  list<string|null>  $parts
     */
    private function searchable(array $parts): string
    {
        return Str::lower(Str::ascii(implode(' ', array_filter($parts, fn ($p) => $p !== null && $p !== ''))));
    }

    /**
     * Every row of a list tool, a page at a time.
     *
     * @return list<array<string, mixed>>
     */
    private function all(string $tool): array
    {
        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->read($tool, ['limit' => self::PAGE, 'offset' => $page * self::PAGE]);
            $items = array_values(array_filter((array) ($data['items'] ?? []), 'is_array'));
            $rows = [...$rows, ...$items];

            if (count($items) < self::PAGE || count($rows) >= (int) ($data['total'] ?? 0)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function read(string $tool, array $arguments): array
    {
        $result = $this->native !== null ? ($this->native)($tool, $arguments) : $this->erp->call($tool, $arguments);

        if (! $result->ok) {
            throw new ErpException("{$tool}: ".Str::limit((string) $result->error(), 300));
        }

        return (array) $result->data;
    }
}
