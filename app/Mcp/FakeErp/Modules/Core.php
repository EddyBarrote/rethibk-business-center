<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Cross-cutting tools: erp.whoami, erp.health, erp.search.
 */
final class Core implements Module
{
    use BuildsTools;

    private const SEARCHABLE = [
        'accounts' => ['name', 'nuit', 'city'],
        'contacts' => ['name', 'email'],
        'leads' => ['title', 'company_name'],
        'projects' => ['name'],
        'invoices' => ['number', 'id'],
        'suppliers' => ['name', 'nuit'],
    ];

    public function tools(): array
    {
        return [
            $this->read('erp.whoami', 'Identifica a organização e o utilizador técnico associados ao token.',
                fn (JsonSchema $s) => [],
                fn () => [
                    'organization' => 'MICOMOC (servidor falso)',
                    'principal' => 'micomoc-agents',
                    'environment' => 'fake',
                    'scopes' => ['crm', 'leads', 'projects', 'invoices', 'procurement', 'expenses'],
                ]),

            $this->read('erp.health', 'Estado do servidor do ERP.',
                fn (JsonSchema $s) => [],
                fn () => ['status' => 'ok', 'server' => 'fake-erp', 'version' => '0.1.0', 'time' => now()->toIso8601String()]),

            $this->read('erp.search', 'Pesquisa transversal: clientes, contactos, leads, projectos, facturas e fornecedores.',
                fn (JsonSchema $s) => [
                    'query' => $s->string()->description('Texto a procurar (nome, NUIT, número).')->required(),
                    'limit' => $s->integer()->description('Máximo de resultados.')->min(1)->max(50),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['query' => 'required|string|min:2', 'limit' => 'nullable|integer|min:1|max:50']);
                    $results = [];

                    foreach (self::SEARCHABLE as $collection => $fields) {
                        foreach ($store->all($collection) as $record) {
                            foreach ($fields as $field) {
                                if (is_string($record[$field] ?? null) && $this->matches($record[$field], $args['query'])) {
                                    $results[] = ['type' => $collection, 'id' => $record['id'], 'label' => $record[$fields[0]] ?? $record['id']];

                                    continue 2;
                                }
                            }
                        }
                    }

                    return ['results' => array_slice($results, 0, $args['limit'] ?? 20)];
                }),
        ];
    }
}
