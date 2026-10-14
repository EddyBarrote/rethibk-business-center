<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemPage;
use App\Support\PdfTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Reads one knowledge base item in full: long documents by parts, files of
 * the repository also by page ("3-5"). Given to every agent.
 */
final class ReadKnowledge extends LocalCapability
{
    private const PART = 12000;

    public function __construct(private readonly KnowledgeAccess $access) {}

    public function key(): string
    {
        return 'knowledge.read';
    }

    public function name(): string
    {
        return 'Ler item de conhecimento';
    }

    public function description(): string
    {
        return 'Lê um artigo ou ficheiro da base de conhecimento pelo id (os ids vêm de memory.search ou knowledge.browse). Nos PDF podes ler só umas páginas (pages: "3" ou "3-5"); o resultado da pesquisa diz em que página está o excerto. Para ver tabelas, assinaturas ou carimbos como estão no original, usa knowledge.view.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'pages' => $schema->string()->description('Opcional, nos PDF: as páginas a ler, ex.: "4" ou "3-5".'),
            'offset' => $schema->integer()->min(0)->description('Para ler documentos longos por partes.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $arguments = Validator::make($arguments, ['id' => 'required|integer', 'pages' => 'nullable|string|max:40', 'offset' => 'nullable|integer|min:0'])->validate();
        $item = $this->access->items($context->agent)->with(['domain:id,name', 'folder'])->find($arguments['id']);

        if (! $item instanceof KnowledgeItem) {
            return CapabilityResult::error('Item não encontrado ou sem acesso.');
        }

        $header = "[{$item->type->label()} #{$item->id}] {$item->title}"
            .($item->domain ? " · {$item->domain->name}" : '')
            .($item->folder ? ' / '.$item->folder->path() : '')
            .($item->filename ? " · ficheiro {$item->filename}" : '')
            .($item->pages ? " · {$item->pages} ".($item->pages === 1 ? 'página' : 'páginas') : '');
        $notes = array_filter([
            $item->isReading() ? 'Este ficheiro ainda está a ser lido: o texto pode estar incompleto.' : null,
            $item->reading_note,
            $item->text_source === 'none' ? 'Não foi possível tirar texto deste ficheiro; vê o original com knowledge.view.' : null,
        ]);
        $notes = $notes === [] ? '' : "\n(".implode(' ', $notes).')';

        if (filled($arguments['pages'] ?? null) && $item->pages) {
            $wanted = PdfTools::range($arguments['pages'], $item->pages, 30);
            $pages = $item->pageTexts()->whereIn('page', $wanted)->get();

            if ($wanted === []) {
                return CapabilityResult::error("Páginas inválidas: o documento tem {$item->pages}.");
            }

            $text = collect($wanted)->map(function (int $number) use ($pages) {
                $page = $pages->firstWhere('page', $number);

                return "[Página {$number}]\n".($page instanceof KnowledgeItemPage ? $page->text : '(sem texto)');
            })->implode("\n\n");

            return new CapabilityResult(true, "{$header}{$notes}\n\n".$this->fence($item, mb_substr($text, 0, self::PART * 2)), ['id' => $item->id, 'pages' => $wanted]);
        }

        $offset = $arguments['offset'] ?? 0;
        $length = mb_strlen($item->content);
        $part = mb_substr($item->content, $offset, self::PART);
        $more = $offset + self::PART < $length ? "\n\n[Continua: use offset=".($offset + self::PART).' para ler o resto'.($item->pages ? ', ou pages para ir a uma página' : '').'.]' : '';

        return new CapabilityResult(true, "{$header}{$notes}\n\n".$this->fence($item, $part).$more, ['id' => $item->id, 'offset' => $offset, 'length' => $length]);
    }

    private function fence(KnowledgeItem $item, string $text): string
    {
        return $item->is_external ? "<dados_externos_nao_confiaveis>\n{$text}\n</dados_externos_nao_confiaveis>" : $text;
    }
}
