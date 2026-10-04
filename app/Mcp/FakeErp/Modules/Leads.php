<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpException;
use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;

final class Leads implements Module
{
    use BuildsTools;

    private const STATUSES = ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost'];

    private const SOURCES = ['email', 'tender', 'referral', 'website', 'phone', 'other'];

    public function tools(): array
    {
        return [
            $this->write('leads.create', 'Regista uma oportunidade comercial.',
                fn (JsonSchema $s) => [
                    'title' => $s->string()->required(),
                    'account_id' => $s->string()->description('Cliente existente, se houver.'),
                    'company_name' => $s->string()->description('Obrigatório quando não há account_id.'),
                    'source' => $s->string()->enum(self::SOURCES)->required(),
                    'estimated_value' => $s->number()->min(0)->description('Valor estimado em MZN.'),
                    'notes' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['title' => 'required|string|max:255', 'account_id' => 'nullable|string', 'company_name' => 'required_without:account_id|nullable|string|max:255', 'source' => 'required|in:'.implode(',', self::SOURCES), 'estimated_value' => 'nullable|numeric|min:0', 'notes' => 'nullable|string']);
                    $companyName = $args['company_name'] ?? null;

                    if (isset($args['account_id'])) {
                        $companyName = $store->find('accounts', $args['account_id'], 'Cliente')['name'];
                    }

                    return ['lead' => $store->insert('leads', 'LEAD', [
                        'title' => $args['title'], 'account_id' => $args['account_id'] ?? null, 'company_name' => $companyName,
                        'source' => $args['source'], 'status' => 'new', 'estimated_value' => (float) ($args['estimated_value'] ?? 0),
                        'currency' => 'MZN', 'notes' => $args['notes'] ?? '', 'documents' => [],
                    ])];
                }),

            $this->write('leads.update', 'Actualiza o estado, valor ou notas de uma oportunidade.',
                fn (JsonSchema $s) => [
                    'lead_id' => $s->string()->required(),
                    'status' => $s->string()->enum(self::STATUSES),
                    'estimated_value' => $s->number()->min(0),
                    'notes' => $s->string()->description('Substitui as notas actuais.'),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['lead_id' => 'required|string', 'status' => 'nullable|in:'.implode(',', self::STATUSES), 'estimated_value' => 'nullable|numeric|min:0', 'notes' => 'nullable|string']);
                    $store->find('leads', $args['lead_id'], 'Lead');

                    return ['lead' => $store->update('leads', $args['lead_id'], Arr::except(array_filter($args, fn ($v) => $v !== null), ['lead_id']))];
                }),

            $this->read('leads.search', 'Procura oportunidades por texto, estado ou cliente.',
                fn (JsonSchema $s) => [
                    'query' => $s->string(),
                    'status' => $s->string()->enum(self::STATUSES),
                    'account_id' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['query' => 'nullable|string', 'status' => 'nullable|in:'.implode(',', self::STATUSES), 'account_id' => 'nullable|string']);

                    return ['leads' => array_values(array_filter($store->all('leads'), fn (array $l) => $this->matches($l['title'].' '.$l['company_name'], (string) ($args['query'] ?? ''))
                        && (! isset($args['status']) || $l['status'] === $args['status'])
                        && (! isset($args['account_id']) || $l['account_id'] === $args['account_id'])))];
                }),

            $this->write('leads.attach_document', 'Associa um documento (por referência) a uma oportunidade.',
                fn (JsonSchema $s) => [
                    'lead_id' => $s->string()->required(),
                    'filename' => $s->string()->required(),
                    'reference' => $s->string()->description('URL ou referência do ficheiro na plataforma.')->required(),
                    'description' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['lead_id' => 'required|string', 'filename' => 'required|string|max:255', 'reference' => 'required|string|max:2048', 'description' => 'nullable|string']);
                    $lead = $store->find('leads', $args['lead_id'], 'Lead');

                    if ($lead['status'] === 'lost') {
                        throw new FakeErpException("A lead {$lead['id']} está perdida; não aceita documentos.");
                    }

                    $document = $store->insert('documents', 'DOC', Arr::only($args, ['lead_id', 'filename', 'reference', 'description']));
                    $store->update('leads', $lead['id'], ['documents' => [...$lead['documents'], $document['id']]]);

                    return ['document' => $document];
                }),
        ];
    }
}
