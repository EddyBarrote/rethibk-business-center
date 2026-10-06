<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpException;
use App\Mcp\FakeErp\FakeErpFixtures;
use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;

final class Invoices implements Module
{
    use BuildsTools;

    public function tools(): array
    {
        return [
            $this->write('invoices.create_draft', 'Cria um rascunho de factura. Não emite: a emissão é confirmada por um humano.',
                fn (JsonSchema $s) => [
                    'account_id' => $s->string()->required(),
                    'project_id' => $s->string(),
                    'lines' => $s->array()->min(1)->items($s->object([
                        'description' => $s->string()->required(),
                        'quantity' => $s->number()->min(0)->required(),
                        'unit_price' => $s->number()->min(0)->description('Preço unitário em MZN, sem IVA.')->required(),
                    ]))->required(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['account_id' => 'required|string', 'project_id' => 'nullable|string', 'lines' => 'required|array|min:1', 'lines.*.description' => 'required|string', 'lines.*.quantity' => 'required|numeric|min:0', 'lines.*.unit_price' => 'required|numeric|min:0']);
                    $store->find('accounts', $args['account_id'], 'Cliente');

                    if (isset($args['project_id']) && $store->find('projects', $args['project_id'], 'Projecto')['account_id'] !== $args['account_id']) {
                        throw new FakeErpException("O projecto {$args['project_id']} não pertence ao cliente {$args['account_id']}.");
                    }

                    $subtotal = round(array_sum(array_map(fn (array $l) => $l['quantity'] * $l['unit_price'], $args['lines'])), 2);
                    $iva = round($subtotal * FakeErpFixtures::IVA_RATE, 2);

                    return ['invoice' => $store->insert('invoices', 'INV', [
                        'number' => null, 'account_id' => $args['account_id'], 'project_id' => $args['project_id'] ?? null,
                        'status' => 'draft', 'issue_date' => null, 'due_date' => null, 'currency' => 'MZN', 'lines' => $args['lines'],
                        'subtotal' => $subtotal, 'iva' => $iva, 'total' => $subtotal + $iva, 'amount_paid' => 0.0,
                    ])];
                }),

            // Section 8.3 lists invoices.issue, but also says no tool issues.
            // Here it only records the issue request against an approval
            // reference; the invoice is issued when a human confirms in the ERP.
            // See docs/ERP-MCP-CONTRACT.md.
            $this->write('invoices.issue', 'Pede a emissão de um rascunho aprovado. Não emite: fica a aguardar confirmação humana no ERP.',
                fn (JsonSchema $s) => [
                    'invoice_id' => $s->string()->required(),
                    'approval_reference' => $s->string()->description('Referência da aprovação humana na plataforma.')->required(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['invoice_id' => 'required|string', 'approval_reference' => 'required|string']);
                    $invoice = $store->find('invoices', $args['invoice_id'], 'Factura');

                    if ($invoice['status'] !== 'draft') {
                        throw new FakeErpException("A factura {$invoice['id']} não é um rascunho (estado: {$invoice['status']}).");
                    }

                    return ['invoice' => $store->update('invoices', $invoice['id'], [
                        'status' => 'pending_confirmation',
                        'approval_reference' => $args['approval_reference'],
                    ])];
                }),

            $this->read('invoices.list_receivables', 'Facturas emitidas por receber, com dias de atraso.',
                fn (JsonSchema $s) => [
                    'account_id' => $s->string(),
                    'overdue_only' => $s->boolean(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['account_id' => 'nullable|string', 'overdue_only' => 'nullable|boolean']);
                    $today = Carbon::today();
                    $receivables = [];

                    foreach ($store->all('invoices') as $invoice) {
                        $outstanding = round($invoice['total'] - $invoice['amount_paid'], 2);

                        if ($invoice['status'] !== 'issued' || $outstanding <= 0 || (isset($args['account_id']) && $invoice['account_id'] !== $args['account_id'])) {
                            continue;
                        }

                        $daysOverdue = max(0, (int) Carbon::parse($invoice['due_date'])->diffInDays($today, false));

                        if (($args['overdue_only'] ?? false) && $daysOverdue === 0) {
                            continue;
                        }

                        $receivables[] = [...$invoice, 'outstanding' => $outstanding, 'days_overdue' => $daysOverdue];
                    }

                    return ['receivables' => $receivables, 'total_outstanding' => round(array_sum(array_column($receivables, 'outstanding')), 2), 'currency' => 'MZN'];
                }),

            $this->read('invoices.get', 'Detalhe de uma factura.',
                fn (JsonSchema $s) => ['invoice_id' => $s->string()->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['invoice_id' => 'required|string']);

                    return ['invoice' => $store->find('invoices', $args['invoice_id'], 'Factura')];
                }),
        ];
    }
}
