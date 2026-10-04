<?php

namespace App\Documents;

use App\Ai\Knowledge\KnowledgeBase;
use App\Documents\Renderers\DocxRenderer;
use App\Documents\Renderers\PdfRenderer;
use App\Documents\Renderers\PptxRenderer;
use App\Documents\Renderers\Renderer;
use App\Documents\Renderers\XlsxRenderer;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\GeneratedDocument;
use App\Models\KnowledgeItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns Markdown into a branded Word, PowerPoint, Excel or PDF file, kept
 * on the private disk for people to preview and download, and optionally
 * filed in the knowledge base.
 */
final class DocumentGenerator
{
    public const DISK = 'local';

    public function __construct(private readonly KnowledgeBase $knowledge) {}

    public function generate(
        DocumentFormat $format,
        DocumentSpec $spec,
        ?Agent $agent = null,
        ?AgentRun $run = null,
        ?User $user = null,
    ): GeneratedDocument {
        $temp = tempnam(sys_get_temp_dir(), 'doc');

        try {
            $this->renderer($format)->render($spec, Brand::current(), $temp);

            $path = 'documents/'.Str::uuid().'.'.$format->value;
            $stream = fopen($temp, 'r');
            Storage::disk(self::DISK)->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            return GeneratedDocument::query()->create([
                'agent_id' => $agent?->id,
                'agent_run_id' => $run?->id,
                'created_by_user_id' => $user?->id,
                'title' => $spec->title,
                'format' => $format->value,
                'template' => $spec->template->value,
                'source' => $spec->markdown,
                'disk' => self::DISK,
                'path' => $path,
                'filename' => self::filename($spec->title, $format),
                'size_bytes' => (int) filesize($temp),
            ]);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Files a copy in the knowledge base, searchable by its Markdown source.
     *
     * @param  array<string, mixed>  $attributes  domain, folder and status
     */
    public function fileInKnowledge(GeneratedDocument $document, ?Model $author, array $attributes = []): KnowledgeItem
    {
        $item = $this->knowledge->rememberFile(
            Storage::disk($document->disk)->path($document->path),
            $document->filename,
            DocumentFormat::from($document->format)->mime(),
            $document->title,
            $author,
            ['content' => $document->source, 'source_type' => $document->getMorphClass(), 'source_id' => $document->id, ...$attributes],
        );

        $document->forceFill(['knowledge_item_id' => $item->id])->save();

        return $item;
    }

    public static function filename(string $title, DocumentFormat $format): string
    {
        return (Str::slug(Str::limit($title, 80, '')) ?: 'documento').'.'.$format->value;
    }

    private function renderer(DocumentFormat $format): Renderer
    {
        return match ($format) {
            DocumentFormat::Docx => new DocxRenderer,
            DocumentFormat::Pptx => new PptxRenderer,
            DocumentFormat::Xlsx => new XlsxRenderer,
            DocumentFormat::Pdf => new PdfRenderer,
        };
    }
}
