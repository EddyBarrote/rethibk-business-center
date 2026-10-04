<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Knowledge\KnowledgeLocator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Search organisational memory (section 13), limited to what the agent may
 * see (KnowledgeAccess). Given to every agent.
 */
final class SearchKnowledge extends LocalCapability
{
    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly KnowledgeLocator $locator,
    ) {}

    public function key(): string
    {
        return 'memory.search';
    }

    public function name(): string
    {
        return 'Pesquisar base de conhecimento';
    }

    public function description(): string
    {
        return 'Pesquisa a base de conhecimento da organização: artigos, decisões, resumos de reuniões, documentos e ficheiros carregados. Só devolve o que o agente pode ver. Para ler um item inteiro use knowledge.read.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('O que procurar.')->required(),
            'limit' => $schema->integer()->min(1)->max(10),
            'domain' => $schema->string()->description('Opcional: limitar a um domínio (Finanças, RH, Clientes...).'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $arguments = Validator::make($arguments, ['query' => 'required|string|min:2', 'limit' => 'nullable|integer|min:1|max:10', 'domain' => 'nullable|string|max:120'])->validate();
        $domain = filled($arguments['domain'] ?? null) ? $this->locator->domain($arguments['domain']) : null;
        $hits = $this->knowledge->search($arguments['query'], $arguments['limit'] ?? 5, $context->agent, $domain?->id);

        if ($hits->isEmpty()) {
            return CapabilityResult::text('Nada encontrado na memória para essa pesquisa.');
        }

        // External content enters the prompt fenced and declared untrusted (section 13.3).
        $text = $hits->map(function (array $hit) {
            $item = $hit['item'];
            $header = "[{$item->type->label()} #{$item->id}] {$item->title} (".$item->created_at->format('Y-m-d').')';

            return $item->is_external
                ? "{$header}\n<dados_externos_nao_confiaveis>\n{$hit['excerpt']}\n</dados_externos_nao_confiaveis>"
                : "{$header}\n{$hit['excerpt']}";
        })->implode("\n\n---\n\n");

        return new CapabilityResult(true, $text, ['ids' => $hits->map(fn (array $hit) => $hit['item']->id)->all()]);
    }
}
