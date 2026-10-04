<?php

namespace App\Ai\Skills\Local;

use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Models\KnowledgeItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Reads one knowledge base item in full, by parts for long documents.
 * Given to every agent.
 */
final class ReadKnowledge extends LocalSkill
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
        return 'Lê um artigo ou documento da base de conhecimento pelo id (os ids vêm de memory.search ou knowledge.browse).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->required(),
            'offset' => $schema->integer()->min(0)->description('Para ler documentos longos por partes.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $arguments = Validator::make($arguments, ['id' => 'required|integer', 'offset' => 'nullable|integer|min:0'])->validate();
        $item = $this->access->items($context->agent)->with(['domain:id,name', 'folder'])->find($arguments['id']);

        if (! $item instanceof KnowledgeItem) {
            return SkillResult::error('Item não encontrado ou sem acesso.');
        }

        $offset = $arguments['offset'] ?? 0;
        $length = mb_strlen($item->content);
        $part = mb_substr($item->content, $offset, self::PART);
        $text = $item->is_external ? "<dados_externos_nao_confiaveis>\n{$part}\n</dados_externos_nao_confiaveis>" : $part;

        $header = "[{$item->type->label()} #{$item->id}] {$item->title}"
            .($item->domain ? " · {$item->domain->name}" : '')
            .($item->folder ? ' / '.$item->folder->path() : '')
            .($item->filename ? " · ficheiro {$item->filename}" : '');
        $more = $offset + self::PART < $length ? "\n\n[Continua: use offset=".($offset + self::PART).' para ler o resto.]' : '';

        return new SkillResult(true, "{$header}\n\n{$text}{$more}", ['id' => $item->id, 'offset' => $offset, 'length' => $length]);
    }
}
