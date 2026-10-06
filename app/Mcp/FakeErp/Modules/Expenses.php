<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class Expenses implements Module
{
    use BuildsTools;

    private const CATEGORIES = ['combustível', 'deslocações', 'materiais', 'epi', 'comunicações', 'manutenção', 'serviços', 'outros'];

    public function tools(): array
    {
        return [
            $this->write('expenses.create', 'Regista uma despesa em rascunho. Não paga.',
                fn (JsonSchema $s) => [
                    'description' => $s->string()->required(),
                    'amount' => $s->number()->min(0)->description('Valor em MZN, com IVA.')->required(),
                    'date' => $s->string()->format('date')->required(),
                    'project_id' => $s->string(),
                    'supplier' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['description' => 'required|string|max:255', 'amount' => 'required|numeric|min:0', 'date' => 'required|date_format:Y-m-d', 'project_id' => 'nullable|string', 'supplier' => 'nullable|string|max:255']);

                    if (isset($args['project_id'])) {
                        $store->find('projects', $args['project_id'], 'Projecto');
                    }

                    return ['expense' => $store->insert('expenses', 'EXP', [
                        'description' => $args['description'], 'amount' => (float) $args['amount'], 'currency' => 'MZN', 'date' => $args['date'],
                        'project_id' => $args['project_id'] ?? null, 'supplier' => $args['supplier'] ?? null, 'category' => null, 'status' => 'draft',
                    ])];
                }),

            $this->write('expenses.classify', 'Classifica uma despesa por categoria e, opcionalmente, projecto.',
                fn (JsonSchema $s) => [
                    'expense_id' => $s->string()->required(),
                    'category' => $s->string()->enum(self::CATEGORIES)->required(),
                    'project_id' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['expense_id' => 'required|string', 'category' => 'required|in:'.implode(',', self::CATEGORIES), 'project_id' => 'nullable|string']);
                    $expense = $store->find('expenses', $args['expense_id'], 'Despesa');

                    if (isset($args['project_id'])) {
                        $store->find('projects', $args['project_id'], 'Projecto');
                    }

                    return ['expense' => $store->update('expenses', $expense['id'], [
                        'category' => $args['category'], 'project_id' => $args['project_id'] ?? $expense['project_id'], 'status' => 'classified',
                    ])];
                }),

            $this->read('expenses.list_by_project', 'Lista as despesas de um projecto com o total.',
                fn (JsonSchema $s) => ['project_id' => $s->string()->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['project_id' => 'required|string']);
                    $store->find('projects', $args['project_id'], 'Projecto');
                    $expenses = array_values(array_filter($store->all('expenses'), fn (array $e) => $e['project_id'] === $args['project_id']));

                    return ['expenses' => $expenses, 'total' => round(array_sum(array_column($expenses, 'amount')), 2), 'currency' => 'MZN'];
                }),
        ];
    }
}
