<?php

use App\Erp\ErpCallResult;
use App\Erp\ErpGateway;
use App\Jobs\SyncErpSales;
use App\Models\ErpSale;
use App\Models\Tenant;
use App\Sales\SalesHistory;
use Illuminate\Support\Facades\Queue;

// Past sales for proposals (docs/DECISOES.md, "Vendas parecidas"): a copy of
// the Perfex invoices, quotes and catalogue, searched by what was sold.

/**
 * @param  list<string>  $calls
 */
function salesErp(array &$calls = [], array $invoices = []): SalesHistory
{
    $invoices = $invoices ?: [
        ['id' => '185', 'formatted_number' => 'INV-2026072', 'customer' => 'Picasso Eventos SU LDA', 'customer_id' => '32', 'date' => '2026-09-30', 'status' => '2', 'status_label' => 'paid', 'total' => '160108.80', 'currency' => 'MZN'],
        ['id' => '180', 'formatted_number' => 'INV-2026067', 'customer' => 'TRIPLE VISION ENTRETENIMENTO', 'customer_id' => '78', 'date' => '2026-09-08', 'status' => '1', 'status_label' => 'unpaid', 'total' => '1624.00', 'currency' => 'MZN'],
    ];
    $details = [
        '185' => ['clientnote' => '', 'terms' => '', 'items' => [['description' => 'Serviço Cashless', 'long_description' => 'Serviço Cashless Reactive - All White Affair 2026', 'qty' => '1.00', 'rate' => '138024.83', 'amount' => 138024.83, 'taxes' => [['name' => 'IVA', 'rate' => '16.00']]]]],
        '180' => ['clientnote' => '', 'terms' => '', 'items' => [['description' => 'Tickets by Reactive ', 'long_description' => "Venda de Bilhetes online na plataforma Reactive  \r\n7% de comissão por bilhete", 'qty' => '1.00', 'rate' => '1400.00', 'amount' => 1400]]],
        '40' => ['clientnote' => 'Pagamentos: 50% na assinatura', 'terms' => '', 'items' => [['description' => 'Serviço Cashless Reactive', 'long_description' => "Numero de Topups - 4\r\nMaquinas de venda total - 14", 'qty' => '1.00', 'rate' => '45000.00', 'amount' => 45000]]],
    ];

    $page = fn (array $items) => ['total' => count($items), 'limit' => 100, 'offset' => 0, 'items' => $items];

    return new SalesHistory(app(ErpGateway::class), function (string $tool, array $arguments) use (&$calls, $invoices, $details, $page): ErpCallResult {
        $calls[] = $tool;

        $data = match ($tool) {
            'list_invoices' => $page($invoices),
            'list_estimates' => $page([['id' => '40', 'formatted_number' => 'EST-000037', 'customer' => 'Ônix eventos e restaurante,LDA', 'date' => '2026-06-03', 'status' => '1', 'status_label' => 'draft', 'total' => '45000.00', 'currency' => 'MZN']]),
            'list_items' => $page([['id' => '265', 'description' => 'Tickets by Reactive ', 'long_description' => "Venda de Bilhetes online na plataforma Reactive \r\n7% de comissão por bilhete", 'rate' => '0.00', 'unit' => 'Un', 'taxrate' => '16.00', 'group_name' => null]]),
            'get_invoice', 'get_estimate' => $details[(string) $arguments['id']],
            default => null,
        };

        return $data === null
            ? new ErpCallResult($tool, false, null, "{$tool} não previsto", 1)
            : new ErpCallResult($tool, true, $data, '', 1);
    });
}

it('copies invoices, quotes and catalogue items with their lines', function () {
    asTenant(Tenant::factory()->create(), function () {
        $calls = [];
        $counts = salesErp($calls)->sync();

        expect($counts)->toMatchArray(['invoice' => 2, 'estimate' => 1, 'item' => 1, 'read' => 3])
            ->and(ErpSale::query()->count())->toBe(4)
            ->and(ErpSale::query()->where('erp_id', '185')->first()->lines[0])->toMatchArray(['description' => 'Serviço Cashless', 'rate' => 138024.83, 'taxes' => ['IVA 16.00%']]);
    });
});

it('reads again only the documents that changed, and drops those gone', function () {
    asTenant(Tenant::factory()->create(), function () {
        salesErp()->sync();

        $calls = [];
        $counts = salesErp($calls, [['id' => '185', 'formatted_number' => 'INV-2026072', 'customer' => 'Picasso Eventos SU LDA', 'date' => '2026-09-30', 'status' => '2', 'status_label' => 'paid', 'total' => '160108.80', 'currency' => 'MZN']])->sync();

        expect($counts['read'])->toBe(0)
            ->and($counts['removed'])->toBe(1)
            ->and($calls)->not->toContain('get_invoice')
            ->and(ErpSale::query()->where('kind', ErpSale::INVOICE)->pluck('erp_id')->all())->toBe(['185']);
    });
});

it('finds past sales by what was sold, accents and case aside, with the catalogue', function () {
    asTenant(Tenant::factory()->create(), function () {
        $sales = salesErp();
        $sales->sync();

        $cashless = $sales->search('Serviço CASHLESS para evento');
        $tickets = $sales->search('comissão de bilhetes');

        expect(array_column($cashless['documents'], 'number'))->toBe(['INV-2026072', 'EST-000037'])
            ->and($cashless['documents'][1])->toMatchArray(['type' => 'cotação', 'client' => 'Ônix eventos e restaurante,LDA', 'note' => 'Pagamentos: 50% na assinatura'])
            ->and($cashless['documents'][1]['lines'][0]['matches'])->toBeTrue()
            ->and(array_column($tickets['documents'], 'number'))->toBe(['INV-2026067'])
            ->and($tickets['catalogue'][0])->toMatchArray(['erp_item_id' => '265', 'tax' => '16.00%'])
            ->and($sales->search('cashless', 'estimate')['documents'])->toHaveCount(1)
            ->and($sales->search('cashless', 'all', 'Picasso')['documents'])->toHaveCount(1)
            ->and($sales->search('de para')['documents'])->toBe([]);
    });
});

it('gives agents the past sales, and asks for the copy when there is none yet', function () {
    Queue::fake();

    asTenant(Tenant::factory()->create(), function () {
        $agent = templateAgent('client_manager');

        expect($agent->capabilities->pluck('key'))->toContain('sales.similar');

        $first = runCapability($agent, 'sales.similar', ['query' => 'cashless']);
        expect($first->ok)->toBeFalse();
        Queue::assertPushed(SyncErpSales::class);

        app()->instance(SalesHistory::class, $sales = salesErp());
        $sales->sync();

        $result = runCapability($agent, 'sales.similar', ['query' => 'cashless']);
        expect($result->ok)->toBeTrue()
            ->and(array_column($result->data['documents'], 'number'))->toContain('INV-2026072')
            ->and($result->data['note'])->toContain('não tabela');
    });
});
