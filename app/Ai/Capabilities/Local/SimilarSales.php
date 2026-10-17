<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Jobs\SyncErpSales;
use App\Sales\SalesHistory;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Past sales of a service, for proposals (docs/DECISOES.md, "Vendas
 * parecidas"): the ERP catalogue items and the invoices and quotes whose lines
 * name it, from the platform's copy of the ERP.
 */
final class SimilarSales extends LocalCapability
{
    public function __construct(private readonly SalesHistory $sales) {}

    public function key(): string
    {
        return 'sales.similar';
    }

    public function name(): string
    {
        return 'Vendas parecidas';
    }

    public function description(): string
    {
        return 'Procura no ERP o que já se vendeu de um serviço ou produto: os artigos do catálogo e as facturas e cotações cujas linhas o mencionam (por exemplo «cashless», «bilhetes», «check-in», «convites»), com cliente, data, quantidades, preços, notas e condições. Usa-o antes de preparar uma proposta ou cotação. '
            .'O catálogo é o preço de tabela; uma factura ou cotação é o que se fez com um cliente concreto, não um preço para outro: diz de que documento vem cada valor e pergunta a quem decide os preços quando o histórico varia ou não cobre o pedido.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('O que se vendeu, em poucas palavras: «cashless», «bilhetes online», «credenciação convidados».')->required(),
            'kind' => $schema->string()->enum(['all', 'invoice', 'estimate'])->description('all (por omissão), invoice só facturas, estimate só cotações.'),
            'client' => $schema->string()->description('Só vendas a este cliente (parte do nome).'),
            'limit' => $schema->integer()->description('Quantos documentos, até 20 (por omissão 8).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return CapabilityResult::error('Diz o serviço ou produto a procurar.');
        }

        $synced = $this->sales->lastSyncedAt();

        if ($synced === null) {
            SyncErpSales::dispatch($context->agent->tenant_id);

            return CapabilityResult::error('A plataforma ainda não tem a cópia das vendas do ERP; pedi-a agora. Tenta outra vez daqui a uns minutos.');
        }

        $kind = in_array($arguments['kind'] ?? null, ['invoice', 'estimate'], true) ? $arguments['kind'] : 'all';
        $found = $this->sales->search($query, $kind, isset($arguments['client']) ? (string) $arguments['client'] : null, (int) ($arguments['limit'] ?? 8));

        return CapabilityResult::data([
            'copied_from_erp_at' => $synced->toDateTimeString(),
            'catalogue' => $found['catalogue'],
            'documents' => $found['documents'],
            'note' => $found['catalogue'] === [] && $found['documents'] === []
                ? 'Nada encontrado. Tenta outras palavras (o nome que se usa nas facturas) ou pergunta a quem decide os preços.'
                : 'Valores de documentos são histórico de clientes concretos, não tabela. Cita o número do documento e confirma o preço a usar com quem decide.',
        ]);
    }
}
