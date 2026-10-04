<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpException;
use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class Procurement implements Module
{
    use BuildsTools;

    public function tools(): array
    {
        return [
            $this->write('procurement.create_rfq', 'Cria um pedido de cotação (rascunho) a enviar a fornecedores.',
                fn (JsonSchema $s) => [
                    'title' => $s->string()->required(),
                    'project_id' => $s->string(),
                    'items' => $s->array()->min(1)->items($s->object([
                        'description' => $s->string()->required(),
                        'quantity' => $s->number()->min(0)->required(),
                        'unit' => $s->string(),
                    ]))->required(),
                    'supplier_ids' => $s->array()->items($s->string())->required(),
                    'due_date' => $s->string()->format('date')->description('Data limite para as cotações.'),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['title' => 'required|string|max:255', 'project_id' => 'nullable|string', 'items' => 'required|array|min:1', 'items.*.description' => 'required|string', 'items.*.quantity' => 'required|numeric|min:0', 'items.*.unit' => 'nullable|string', 'supplier_ids' => 'required|array|min:1', 'supplier_ids.*' => 'string', 'due_date' => 'nullable|date_format:Y-m-d']);

                    if (isset($args['project_id'])) {
                        $store->find('projects', $args['project_id'], 'Projecto');
                    }

                    foreach ($args['supplier_ids'] as $supplierId) {
                        $store->find('suppliers', $supplierId, 'Fornecedor');
                    }

                    return ['rfq' => $store->insert('rfqs', 'RFQ', [
                        'title' => $args['title'], 'project_id' => $args['project_id'] ?? null, 'status' => 'draft',
                        'items' => $args['items'], 'supplier_ids' => array_values($args['supplier_ids']), 'due_date' => $args['due_date'] ?? null,
                    ])];
                }),

            $this->read('procurement.list_suppliers', 'Lista fornecedores, filtrando por categoria ou texto.',
                fn (JsonSchema $s) => [
                    'category' => $s->string()->description('Ex.: aço, cimento, combustível, informática, epi.'),
                    'query' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['category' => 'nullable|string', 'query' => 'nullable|string']);

                    return ['suppliers' => array_values(array_filter($store->all('suppliers'), fn (array $s) => (! isset($args['category']) || in_array(mb_strtolower($args['category']), $s['categories'], true))
                        && $this->matches($s['name'].' '.$s['nuit'], (string) ($args['query'] ?? ''))))];
                }),

            $this->read('procurement.compare_quotes', 'Compara as cotações recebidas para um pedido, da mais barata para a mais cara.',
                fn (JsonSchema $s) => ['rfq_id' => $s->string()->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['rfq_id' => 'required|string']);
                    $rfq = $store->find('rfqs', $args['rfq_id'], 'Pedido de cotação');
                    $quotes = array_values(array_filter($store->all('quotes'), fn (array $q) => $q['rfq_id'] === $rfq['id']));

                    if ($quotes === []) {
                        return ['rfq' => $rfq, 'quotes' => [], 'cheapest_quote_id' => null, 'fastest_quote_id' => null];
                    }

                    usort($quotes, fn (array $a, array $b) => $a['total'] <=> $b['total']);
                    $cheapest = $quotes[0]['total'];
                    $quotes = array_map(fn (array $q) => [
                        ...$q,
                        'supplier' => $store->find('suppliers', $q['supplier_id'], 'Fornecedor')['name'],
                        'difference_to_cheapest_pct' => round(($q['total'] - $cheapest) / $cheapest * 100, 1),
                    ], $quotes);
                    $fastest = $quotes;
                    usort($fastest, fn (array $a, array $b) => $a['delivery_days'] <=> $b['delivery_days']);

                    return ['rfq' => $rfq, 'quotes' => $quotes, 'cheapest_quote_id' => $quotes[0]['id'], 'fastest_quote_id' => $fastest[0]['id']];
                }),

            $this->write('procurement.create_po_draft', 'Cria um rascunho de nota de encomenda a partir de uma cotação. Não confirma a encomenda.',
                fn (JsonSchema $s) => [
                    'rfq_id' => $s->string()->required(),
                    'quote_id' => $s->string()->required(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['rfq_id' => 'required|string', 'quote_id' => 'required|string']);
                    $rfq = $store->find('rfqs', $args['rfq_id'], 'Pedido de cotação');
                    $quote = $store->find('quotes', $args['quote_id'], 'Cotação');

                    if ($quote['rfq_id'] !== $rfq['id']) {
                        throw new FakeErpException("A cotação {$quote['id']} não responde ao pedido {$rfq['id']}.");
                    }

                    return ['purchase_order' => $store->insert('purchase_orders', 'PO', [
                        'number' => null, 'supplier_id' => $quote['supplier_id'], 'project_id' => $rfq['project_id'],
                        'rfq_id' => $rfq['id'], 'quote_id' => $quote['id'], 'status' => 'draft',
                        'lines' => $rfq['items'], 'total' => $quote['total'], 'currency' => 'MZN',
                    ])];
                }),

            $this->write('procurement.receive', 'Regista a recepção (total ou parcial) de uma encomenda confirmada. Não paga.',
                fn (JsonSchema $s) => [
                    'po_id' => $s->string()->required(),
                    'lines' => $s->array()->items($s->object([
                        'description' => $s->string()->required(),
                        'quantity_received' => $s->number()->min(0)->required(),
                    ]))->required(),
                    'notes' => $s->string()->description('Ex.: guia de remessa, faltas, avarias.'),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['po_id' => 'required|string', 'lines' => 'required|array|min:1', 'lines.*.description' => 'required|string', 'lines.*.quantity_received' => 'required|numeric|min:0', 'notes' => 'nullable|string']);
                    $order = $store->find('purchase_orders', $args['po_id'], 'Encomenda');

                    if ($order['status'] !== 'confirmed') {
                        throw new FakeErpException("A encomenda {$order['id']} não está confirmada (estado: {$order['status']}).");
                    }

                    return ['receipt' => $store->insert('receipts', 'GRN', [
                        'po_id' => $order['id'], 'lines' => $args['lines'], 'notes' => $args['notes'] ?? null, 'status' => 'recorded',
                    ])];
                }),
        ];
    }
}
