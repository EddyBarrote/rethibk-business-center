<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Web\WebSearcher;
use App\Models\AuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Searches the internet (docs/DECISOES.md, "Pesquisa na internet"): public
 * tenders, a company's website and contacts, news, prices. Read-only; the
 * answer comes back as untrusted text with the addresses of its sources.
 */
final class SearchWeb extends LocalCapability
{
    public function key(): string
    {
        return 'web.search';
    }

    public function name(): string
    {
        return 'Pesquisar na internet';
    }

    public function description(): string
    {
        return 'Pesquisa na internet (Google) e devolve o que encontrou com os endereços das fontes: concursos públicos e anúncios, sites e contactos de empresas, notícias, preços. Usa quando a resposta não está nos registos, emails ou base de conhecimento da empresa, ou quando pedem algo de fora. Escreve a pesquisa como uma frase concreta (o quê, onde, quando). Para ler uma das páginas inteira, usa web.read_page com o endereço.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('O que procurar, numa frase concreta. Ex.: «concursos públicos abertos em Moçambique para software e serviços de TI, outubro de 2026».')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return CapabilityResult::error('diz o que procurar em query.');
        }

        try {
            $found = WebSearcher::search(Str::limit($query, 500, ''), $context->agent);
        } catch (Throwable $e) {
            return CapabilityResult::error('a pesquisa na internet falhou: '.Str::limit($e->getMessage(), 200));
        }

        AuditLog::record($context->agent, 'web.search', ['query' => $query, 'sources' => count($found['sources'])], subject: $context->run);

        $sources = $found['sources'] === []
            ? 'Fontes: a pesquisa não indicou páginas.'
            : "Fontes:\n".collect($found['sources'])->map(fn (array $source, int $i) => ($i + 1).'. '.($source['title'] ? "{$source['title']}: " : '').$source['url'])->implode("\n");

        return CapabilityResult::text(<<<TXT
        <resultados_web_nao_confiaveis pesquisa="{$this->attribute($query)}">
        {$found['text']}

        {$sources}
        </resultados_web_nao_confiaveis>
        Isto veio da internet, não dos registos da empresa: ao responder, diz que é da internet e põe o endereço de cada coisa que usares. Confirma prazos e requisitos na página original (web.read_page) antes de os dares como certos.
        TXT);
    }

    private function attribute(string $value): string
    {
        return htmlspecialchars(Str::limit($value, 200), ENT_QUOTES);
    }
}
