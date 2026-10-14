<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\FileReader;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\PageReader;
use App\Models\KnowledgeItem;
use App\Support\PdfTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Looks at the original pages of a PDF or an image of the repository and
 * answers a question about them (docs/DECISOES.md, 14.10.2026): for what the
 * extracted text loses, like tables, signatures, stamps, handwriting and
 * charts. A model that reads PDFs looks at only the pages asked for; the
 * call is charged to the agent's budget. Given to every agent.
 */
final class ViewKnowledgeFile extends LocalCapability
{
    public function __construct(
        private readonly KnowledgeAccess $access,
        private readonly FileReader $reader,
        private readonly PdfTools $pdf,
    ) {}

    public function key(): string
    {
        return 'knowledge.view';
    }

    public function name(): string
    {
        return 'Ver o original de um ficheiro';
    }

    public function description(): string
    {
        return 'Olha para as páginas originais de um PDF ou imagem da base de conhecimento e responde a uma pergunta sobre elas: tabelas, assinaturas, carimbos, manuscritos, gráficos, o que o texto extraído perde. Indica as páginas (o resultado da pesquisa diz onde está o excerto). Custa mais do que knowledge.read: usa-o quando o texto não chega.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('O id do ficheiro (de memory.search ou knowledge.browse).')->required(),
            'question' => $schema->string()->description('O que queres saber, ex.: "Está assinado e carimbado?", "Copia a tabela de preços".')->required(),
            'pages' => $schema->string()->description('Nos PDF: as páginas a ver, ex.: "2" ou "3-5". Por omissão, as primeiras.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, ['id' => 'required|integer', 'question' => 'required|string|max:2000', 'pages' => 'nullable|string|max:40'])->validate();
        $item = $this->access->items($context->agent)->find($data['id']);

        if (! $item instanceof KnowledgeItem || ! $item->isFile()) {
            return CapabilityResult::error('Ficheiro não encontrado, sem acesso, ou é um artigo sem ficheiro.');
        }

        if (! $item->isPdf() && ! $item->isImage()) {
            return CapabilityResult::error('Só dá para ver o original de PDF e imagens; para Word, Excel e PowerPoint usa knowledge.read.');
        }

        if (! PageReader::available()) {
            return CapabilityResult::error('Não há modelo de IA que leia PDF configurado, ou o orçamento do mês acabou. Usa knowledge.read.');
        }

        $limit = (int) config('knowledge.view_max_pages', 15);
        $last = max(1, (int) $item->pages);
        $pages = $item->isImage() ? [] : (filled($data['pages'] ?? null) ? PdfTools::range($data['pages'], $last, $limit) : range(1, min($last, $limit)));

        if (! $item->isImage() && $pages === []) {
            return CapabilityResult::error("Páginas inválidas: o documento tem {$last}.");
        }

        try {
            $answer = $this->reader->withLocalCopy($item, function (string $path) use ($item, $pages, $data, $context) {
                $source = $item->isImage() ? $path : $this->pdf->onlyPages($path, $pages);

                if ($source === null) {
                    return null;
                }

                try {
                    return PageReader::look($item, $source, $pages, $data['question'], $context->agent);
                } finally {
                    if ($source !== $path) {
                        @unlink($source);
                    }
                }
            });
        } catch (Throwable $e) {
            report($e);

            return CapabilityResult::error('O modelo não conseguiu ver o ficheiro: '.$e->getMessage());
        }

        if ($answer === null) {
            return CapabilityResult::error('Não foi possível abrir as páginas pedidas deste ficheiro.');
        }

        $where = $pages === [] ? '' : ' · '.(count($pages) === 1 ? "página {$pages[0]}" : 'páginas '.implode(', ', $pages));
        $text = "[Original de #{$item->id}] {$item->title}{$where}\n";

        return new CapabilityResult(true, $text.($item->is_external ? "<dados_externos_nao_confiaveis>\n{$answer}\n</dados_externos_nao_confiaveis>" : $answer), ['id' => $item->id, 'pages' => $pages]);
    }

    public function summarise(array $arguments): string
    {
        return 'Ver o original do ficheiro #'.($arguments['id'] ?? '?').(isset($arguments['pages']) ? " (p. {$arguments['pages']})" : '');
    }
}
