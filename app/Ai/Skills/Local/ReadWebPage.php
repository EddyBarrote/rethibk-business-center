<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Support\SafeHttp;
use App\Support\TextExtractor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Throwable;

/**
 * ReadTenderSpec of section 7.3: reads a public page or PDF (a tender notice,
 * a caderno de encargos) as untrusted text.
 */
final class ReadWebPage extends LocalSkill
{
    public function __construct(private readonly SafeHttp $http, private readonly TextExtractor $extractor) {}

    public function key(): string
    {
        return 'web.read_page';
    }

    public function name(): string
    {
        return 'Ler página web';
    }

    public function description(): string
    {
        return 'Lê uma página pública ou um PDF na web (anúncio de concurso, caderno de encargos) e devolve o texto.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->required(),
            'offset' => $schema->integer()->min(0),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $url = (string) ($arguments['url'] ?? '');

        try {
            $response = $this->http->get($url);
        } catch (Throwable $e) {
            return SkillResult::error('não foi possível ler a página: '.Str::limit($e->getMessage(), 200));
        }

        if (! $response->successful()) {
            return SkillResult::error("a página respondeu {$response->status()}.");
        }

        $tmp = tempnam(sys_get_temp_dir(), 'web');
        file_put_contents($tmp, $response->body());

        try {
            $text = $this->extractor->extract($tmp, $response->header('Content-Type') ?: null, parse_url($url, PHP_URL_PATH) ?: 'pagina.html');
        } finally {
            @unlink($tmp);
        }

        if ($text === null) {
            return SkillResult::text('A página não tem texto legível.');
        }

        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        $chunk = mb_substr($text, $offset, 12000);
        $more = mb_strlen($text) > $offset + 12000 ? "\n[continua: usa offset ".($offset + 12000).']' : '';

        return SkillResult::text("<conteudo_web_nao_confiavel url=\"{$url}\">\n{$chunk}\n</conteudo_web_nao_confiavel>{$more}");
    }
}
