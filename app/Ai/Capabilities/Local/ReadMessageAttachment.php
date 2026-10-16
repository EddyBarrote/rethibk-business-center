<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Attachments\ModelInput;
use App\Models\MessageAttachment;
use App\Tasks\TaskThread;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;

/**
 * The text of a file sent in this conversation (docs/DECISOES.md, "Anexos nas
 * conversas"): extracted from documents, OCR for images, a transcription for
 * audio. Only files of the thread the agent is working in.
 */
final class ReadMessageAttachment extends LocalCapability
{
    private const PART = 12000;

    public function __construct(private readonly ModelInput $input) {}

    public function key(): string
    {
        return 'files.read';
    }

    public function name(): string
    {
        return 'Ler anexo da conversa';
    }

    public function description(): string
    {
        return 'Lê o texto de um anexo desta conversa pelo id (anexo #id): documentos, imagens com texto, transcrição de áudio. Para documentos longos, lê por partes com offset. Não é preciso para os anexos que já recebeste em ficheiro.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'attachment_id' => $schema->integer()->required(),
            'offset' => $schema->integer()->min(0)->description('Para ler documentos longos por partes.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, ['attachment_id' => 'required|integer', 'offset' => 'nullable|integer|min:0'])->validate();

        $attachment = $context->run->task_id === null ? null : MessageAttachment::query()
            ->ready()
            ->whereIn('task_id', app(TaskThread::class)->readableTaskIds($context->run))
            ->whereNotNull('task_message_id')
            ->find($data['attachment_id']);

        if ($attachment === null) {
            return CapabilityResult::error('Anexo não encontrado nesta conversa.');
        }

        $text = $this->input->text($attachment);

        if ($text === null) {
            return CapabilityResult::text("O {$attachment->reference()} ({$attachment->filename}) não tem texto legível.");
        }

        $offset = (int) ($data['offset'] ?? 0);
        $more = mb_strlen($text) > $offset + self::PART ? "\n[continua: usa offset ".($offset + self::PART).']' : '';

        return CapabilityResult::text("{$attachment->reference()} {$attachment->filename}\n<anexo_nao_confiavel>\n".mb_substr($text, $offset, self::PART)."\n</anexo_nao_confiavel>{$more}");
    }

    public function summarise(array $arguments): string
    {
        return 'Ler anexo #'.($arguments['attachment_id'] ?? '?');
    }
}
