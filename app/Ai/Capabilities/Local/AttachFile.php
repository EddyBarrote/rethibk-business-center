<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Attachments\AttachmentStore;
use App\Models\AgentRun;
use App\Models\GeneratedDocument;
use App\Models\KnowledgeItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * Sends a file with the agent's reply in the conversation (docs/DECISOES.md,
 * "Anexos nas conversas"): a document it generated or a file of the
 * knowledge base it can read. Documents generated in the same turn go on
 * their own; this is for the others.
 */
final class AttachFile extends LocalCapability
{
    public function __construct(
        private readonly AttachmentStore $store,
        private readonly KnowledgeAccess $access,
    ) {}

    public function key(): string
    {
        return 'files.attach';
    }

    public function name(): string
    {
        return 'Anexar ficheiro à resposta';
    }

    public function description(): string
    {
        return 'Anexa um ficheiro à tua resposta nesta conversa: um documento gerado (document_id) ou um ficheiro da base de conhecimento (knowledge_item_id). Os documentos que geras nesta resposta já vão anexados sozinhos.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()->description('Id de um documento gerado (documents.generate).'),
            'knowledge_item_id' => $schema->integer()->description('Id de um item da base de conhecimento com ficheiro.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'document_id' => 'nullable|integer|required_without:knowledge_item_id',
            'knowledge_item_id' => 'nullable|integer',
        ])->validate();

        if ($context->run->task_id === null) {
            return CapabilityResult::error('Só dá para anexar ficheiros numa conversa ou tarefa.');
        }

        if (filled($data['document_id'] ?? null)) {
            $document = GeneratedDocument::query()->find($data['document_id']);

            if ($document === null || ($document->agent_id !== null && $document->agent_id !== $context->agent->id && ! $this->fromThisThread($document, $context))) {
                return CapabilityResult::error('Documento não encontrado.');
            }

            $attachment = $this->store->fromAgent($context->run, $document->filename, $document->disk, $document->path, 'generated', $document->source);
        } else {
            $item = $this->access->items($context->agent)->find($data['knowledge_item_id']);

            if (! $item instanceof KnowledgeItem || $item->path === null || $item->disk === null) {
                return CapabilityResult::error('Ficheiro da base de conhecimento não encontrado, sem acesso, ou é um artigo sem ficheiro.');
            }

            $attachment = $this->store->fromAgent($context->run, (string) ($item->filename ?: $item->title), $item->disk, $item->path, 'knowledge');
        }

        if ($attachment === null) {
            return CapabilityResult::error('O ficheiro já não existe no armazenamento.');
        }

        return CapabilityResult::data(['attached' => $attachment->filename, 'note' => 'Vai anexado à tua resposta.']);
    }

    private function fromThisThread(GeneratedDocument $document, CapabilityContext $context): bool
    {
        return $document->agent_run_id !== null
            && $context->run->task_id !== null
            && AgentRun::query()->whereKey($document->agent_run_id)->where('task_id', $context->run->task_id)->exists();
    }

    public function summarise(array $arguments): string
    {
        return isset($arguments['document_id']) ? "Anexar documento #{$arguments['document_id']}" : 'Anexar ficheiro #'.($arguments['knowledge_item_id'] ?? '?');
    }
}
