<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpException;
use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class Projects implements Module
{
    use BuildsTools;

    private const STATUSES = ['planned', 'in_progress', 'on_hold', 'completed', 'cancelled'];

    /** Allowed status changes. */
    private const TRANSITIONS = [
        'planned' => ['in_progress', 'cancelled'],
        'in_progress' => ['on_hold', 'completed', 'cancelled'],
        'on_hold' => ['in_progress', 'cancelled'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function tools(): array
    {
        return [
            $this->write('projects.create', 'Cria um projecto no estado "planned".',
                fn (JsonSchema $s) => [
                    'account_id' => $s->string()->required(),
                    'name' => $s->string()->required(),
                    'budget' => $s->number()->min(0)->description('Orçamento em MZN.')->required(),
                    'start_date' => $s->string()->format('date')->required(),
                    'end_date' => $s->string()->format('date'),
                    'manager' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['account_id' => 'required|string', 'name' => 'required|string|max:255', 'budget' => 'required|numeric|min:0', 'start_date' => 'required|date_format:Y-m-d', 'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date', 'manager' => 'nullable|string|max:255']);
                    $store->find('accounts', $args['account_id'], 'Cliente');

                    return ['project' => $store->insert('projects', 'PRJ', [
                        'account_id' => $args['account_id'], 'name' => $args['name'], 'status' => 'planned',
                        'budget' => (float) $args['budget'], 'spent' => 0.0, 'currency' => 'MZN',
                        'start_date' => $args['start_date'], 'end_date' => $args['end_date'] ?? null, 'manager' => $args['manager'] ?? null,
                    ])];
                }),

            $this->read('projects.get', 'Ficha de um projecto com execução orçamental, facturas e despesas.',
                fn (JsonSchema $s) => ['project_id' => $s->string()->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['project_id' => 'required|string']);
                    $project = $store->find('projects', $args['project_id'], 'Projecto');

                    return [
                        'project' => $project,
                        'budget_used_pct' => $project['budget'] > 0 ? round($project['spent'] / $project['budget'] * 100, 1) : null,
                        'invoices' => array_values(array_filter($store->all('invoices'), fn (array $i) => $i['project_id'] === $project['id'])),
                        'expenses' => array_values(array_filter($store->all('expenses'), fn (array $e) => $e['project_id'] === $project['id'])),
                    ];
                }),

            $this->read('projects.list', 'Lista os projectos com execução orçamental e facturação, filtrando por estado.',
                fn (JsonSchema $s) => ['status' => $s->string()->enum(self::STATUSES)],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['status' => 'nullable|in:'.implode(',', self::STATUSES)]);
                    $invoices = $store->all('invoices');

                    return ['projects' => array_values(array_map(function (array $p) use ($invoices): array {
                        $issued = array_filter($invoices, fn (array $i) => $i['project_id'] === $p['id'] && $i['status'] !== 'draft');

                        return [...$p, 'invoiced' => round(array_sum(array_column($issued, 'subtotal')), 2)];
                    }, array_filter($store->all('projects'), fn (array $p) => ! isset($args['status']) || $p['status'] === $args['status'])))];
                }),

            $this->read('projects.list_by_account', 'Lista os projectos de um cliente.',
                fn (JsonSchema $s) => [
                    'account_id' => $s->string()->required(),
                    'status' => $s->string()->enum(self::STATUSES),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['account_id' => 'required|string', 'status' => 'nullable|in:'.implode(',', self::STATUSES)]);
                    $store->find('accounts', $args['account_id'], 'Cliente');

                    return ['projects' => array_values(array_filter($store->all('projects'), fn (array $p) => $p['account_id'] === $args['account_id']
                        && (! isset($args['status']) || $p['status'] === $args['status'])))];
                }),

            $this->write('projects.update_status', 'Muda o estado de um projecto, respeitando as transições permitidas.',
                fn (JsonSchema $s) => [
                    'project_id' => $s->string()->required(),
                    'status' => $s->string()->enum(self::STATUSES)->required(),
                    'reason' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['project_id' => 'required|string', 'status' => 'required|in:'.implode(',', self::STATUSES), 'reason' => 'nullable|string']);
                    $project = $store->find('projects', $args['project_id'], 'Projecto');

                    if (! in_array($args['status'], self::TRANSITIONS[$project['status']] ?? [], true)) {
                        throw new FakeErpException("O projecto {$project['id']} não pode passar de {$project['status']} para {$args['status']}.");
                    }

                    return ['project' => $store->update('projects', $project['id'], ['status' => $args['status'], 'status_reason' => $args['reason'] ?? null])];
                }),
        ];
    }
}
