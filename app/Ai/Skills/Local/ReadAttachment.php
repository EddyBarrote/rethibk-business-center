<?php

namespace App\Ai\Skills\Local;

use App\Ai\Skills\LocalSkill;
use App\Ai\Skills\SkillContext;
use App\Ai\Skills\SkillResult;
use App\Models\EmailAttachment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;

/**
 * The text of an attachment, extracted at ingestion (PDF, Word, Excel, OCR
 * for images when Tesseract is installed). ExtractDocumentFields and
 * OcrAttachment of section 7.3 are this plus the model.
 */
final class ReadAttachment extends LocalSkill
{
    public function key(): string
    {
        return 'documents.read_attachment';
    }

    public function name(): string
    {
        return 'Ler anexo';
    }

    public function description(): string
    {
        return 'Devolve o texto de um anexo de email (PDF, Word, Excel, imagem com OCR).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'attachment_id' => $schema->integer()->required(),
            'offset' => $schema->integer()->min(0)->description('Para ler documentos longos por partes.'),
        ];
    }

    public function execute(array $arguments, SkillContext $context): SkillResult
    {
        $attachment = EmailAttachment::query()->find((int) ($arguments['attachment_id'] ?? 0));

        if ($attachment === null) {
            return SkillResult::error('anexo não encontrado.');
        }

        if ($attachment->extracted_text === null) {
            return SkillResult::text("O anexo {$attachment->filename} não tem texto legível ({$attachment->ocr_status}).");
        }

        $offset = max(0, (int) ($arguments['offset'] ?? 0));
        $chunk = mb_substr($attachment->extracted_text, $offset, 12000);
        $more = mb_strlen($attachment->extracted_text) > $offset + 12000 ? "\n[continua: usa offset ".($offset + 12000).']' : '';

        return SkillResult::text("Anexo #{$attachment->id} {$attachment->filename}\n<documento_externo_nao_confiavel>\n{$chunk}\n</documento_externo_nao_confiavel>{$more}");
    }

    public function summarise(array $arguments): string
    {
        return 'Ler anexo #'.Str::limit((string) ($arguments['attachment_id'] ?? ''), 20);
    }
}
