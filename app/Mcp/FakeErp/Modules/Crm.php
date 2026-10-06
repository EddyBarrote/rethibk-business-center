<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;

final class Crm implements Module
{
    use BuildsTools;

    public function tools(): array
    {
        return [
            $this->read('crm.search_accounts', 'Procura clientes por nome, NUIT, cidade ou sector.',
                fn (JsonSchema $s) => [
                    'query' => $s->string()->description('Nome, NUIT, cidade ou sector. Vazio lista todos.'),
                    'status' => $s->string()->enum(['active', 'inactive']),
                    'limit' => $s->integer()->min(1)->max(50),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['query' => 'nullable|string', 'status' => 'nullable|in:active,inactive', 'limit' => 'nullable|integer|min:1|max:50']);
                    $query = (string) ($args['query'] ?? '');

                    $accounts = array_filter($store->all('accounts'), fn (array $a) => (! isset($args['status']) || $a['status'] === $args['status'])
                        && ($this->matches($a['name'], $query) || $this->matches($a['nuit'], $query) || $this->matches($a['city'], $query) || $this->matches($a['sector'], $query)));

                    return ['accounts' => array_slice(array_values($accounts), 0, $args['limit'] ?? 20)];
                }),

            $this->read('crm.get_account', 'Ficha de um cliente com contactos, projectos e saldo em aberto.',
                fn (JsonSchema $s) => ['account_id' => $s->string()->description('Ex.: ACC-0001')->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['account_id' => 'required|string']);
                    $account = $store->find('accounts', $args['account_id'], 'Cliente');
                    $open = array_filter($store->all('invoices'), fn (array $i) => $i['account_id'] === $account['id'] && $i['status'] === 'issued');

                    return [
                        'account' => $account,
                        'contacts' => array_values(array_filter($store->all('contacts'), fn (array $c) => $c['account_id'] === $account['id'])),
                        'projects' => array_values(array_map(fn (array $p) => Arr::only($p, ['id', 'name', 'status']), array_filter($store->all('projects'), fn (array $p) => $p['account_id'] === $account['id']))),
                        'balance_due' => round(array_sum(array_map(fn (array $i) => $i['total'] - $i['amount_paid'], $open)), 2),
                        'currency' => 'MZN',
                    ];
                }),

            $this->write('crm.create_contact', 'Cria um contacto num cliente existente.',
                fn (JsonSchema $s) => [
                    'account_id' => $s->string()->required(),
                    'name' => $s->string()->required(),
                    'email' => $s->string()->format('email'),
                    'phone' => $s->string(),
                    'role' => $s->string()->description('Cargo do contacto.'),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['account_id' => 'required|string', 'name' => 'required|string|max:255', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40', 'role' => 'nullable|string|max:255']);
                    $store->find('accounts', $args['account_id'], 'Cliente');

                    return ['contact' => $store->insert('contacts', 'CNT', Arr::only($args, ['account_id', 'name', 'email', 'phone', 'role']))];
                }),

            $this->write('crm.update_account', 'Actualiza dados de contacto e condições de um cliente. Não altera o NUIT.',
                fn (JsonSchema $s) => [
                    'account_id' => $s->string()->required(),
                    'email' => $s->string()->format('email'),
                    'phone' => $s->string(),
                    'city' => $s->string(),
                    'sector' => $s->string(),
                    'payment_terms_days' => $s->integer()->min(0)->max(180),
                    'status' => $s->string()->enum(['active', 'inactive']),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['account_id' => 'required|string', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40', 'city' => 'nullable|string|max:120', 'sector' => 'nullable|string|max:120', 'payment_terms_days' => 'nullable|integer|min:0|max:180', 'status' => 'nullable|in:active,inactive']);
                    $store->find('accounts', $args['account_id'], 'Cliente');
                    $changes = Arr::except(array_filter($args, fn ($v) => $v !== null), ['account_id']);

                    if ($changes === []) {
                        return ['account' => $store->find('accounts', $args['account_id'], 'Cliente'), 'changed' => []];
                    }

                    return ['account' => $store->update('accounts', $args['account_id'], $changes), 'changed' => array_keys($changes)];
                }),
        ];
    }
}
