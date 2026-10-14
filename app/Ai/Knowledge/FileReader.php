<?php

namespace App\Ai\Knowledge;

use App\Models\GeneratedDocument;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemPage;
use App\Support\PdfTools;
use App\Support\TextExtractor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a file of the repository once, on the queue (docs/DECISOES.md,
 * 14.10.2026): a PDF page by page with pdftotext, and the pages with no text
 * (scans, photos) by a model that looks at the page; other documents with the
 * text extractor. The result is the item's content, with [Página N] lines so
 * agents and people can cite and read by page.
 */
final class FileReader
{
    public const CONTENT_MAX_CHARS = 1_000_000;

    public function __construct(
        private readonly TextExtractor $extractor,
        private readonly PdfTools $pdf,
    ) {}

    /**
     * First pass, without a model: pages, text and thumbnail. Returns the
     * pages that still need a model (no text found), in order.
     *
     * @return list<int>
     */
    public function start(KnowledgeItem $item): array
    {
        $item->forceFill(['reading_status' => KnowledgeItem::READING, 'reading_note' => null])->save();

        return $this->withLocalCopy($item, function (string $path) use ($item): array {
            $generated = $item->source_type === (new GeneratedDocument)->getMorphClass() && trim($item->content) !== '';

            if ($item->isPdf()) {
                $this->thumbnail($item, $path);
                $texts = $this->pdf->pageTexts($path);
                $item->forceFill(['pages' => $this->pdf->pageCount($path) ?? (count($texts) ?: null)])->save();

                // A document the platform generated keeps its Markdown source, which reads better.
                if ($generated) {
                    return [];
                }

                $item->pageTexts()->delete();

                foreach ($texts as $index => $text) {
                    if ($text !== '') {
                        KnowledgeItemPage::query()->create(['knowledge_item_id' => $item->id, 'page' => $index + 1, 'text' => $text, 'source' => KnowledgeItemPage::FROM_TEXT]);
                    }
                }

                $min = (int) config('knowledge.reading.min_chars_per_page', 80);
                $thin = [];

                for ($page = 1; $page <= (int) $item->pages; $page++) {
                    if (mb_strlen((string) preg_replace('/\s+/u', '', $texts[$page - 1] ?? '')) < $min) {
                        $thin[] = $page;
                    }
                }

                return array_slice($thin, 0, (int) config('knowledge.reading.max_model_pages', 300));
            }

            if ($generated) {
                return [];
            }

            if ($item->isImage()) {
                $item->pageTexts()->delete();
                $text = $this->extractor->imageText($path);
                $item->forceFill(['pages' => 1])->save();

                if ($text !== null && mb_strlen($text) >= (int) config('knowledge.reading.min_chars_per_page', 80)) {
                    KnowledgeItemPage::query()->create(['knowledge_item_id' => $item->id, 'page' => 1, 'text' => $text, 'source' => KnowledgeItemPage::FROM_TEXT]);

                    return [];
                }

                return [1];
            }

            $item->forceFill(['content' => (string) $this->extractor->extract($path, $item->mime_type, $item->filename)])->save();

            return [];
        }) ?? [];
    }

    /**
     * Second pass, one batch at a time: the model reads these pages.
     *
     * @param  list<int>  $pages
     */
    public function readWithModel(KnowledgeItem $item, array $pages): void
    {
        $this->withLocalCopy($item, function (string $path) use ($item, $pages): void {
            $source = $item->isImage() ? $path : $this->pdf->onlyPages($path, $pages);

            if ($source === null) {
                $this->note($item, 'Não foi possível separar as páginas digitalizadas deste PDF para as ler (falta o Poppler no servidor).');

                return;
            }

            try {
                foreach (PageReader::transcribe($item, $source, $pages) as $page => $text) {
                    KnowledgeItemPage::query()->updateOrCreate(
                        ['knowledge_item_id' => $item->id, 'page' => $page],
                        ['text' => $text, 'source' => KnowledgeItemPage::FROM_MODEL],
                    );
                }
            } finally {
                if ($source !== $path) {
                    @unlink($source);
                }
            }
        });
    }

    /**
     * Joins the pages into the item's content and says where the text came from.
     */
    public function finish(KnowledgeItem $item): void
    {
        $item->refresh();
        $pages = $item->pageTexts()->get();
        $generated = $item->source_type === (new GeneratedDocument)->getMorphClass() && trim($item->content) !== '';

        if (($item->isPdf() || $item->isImage()) && ! $generated) {
            $several = (int) $item->pages > 1;
            $content = $pages->map(fn (KnowledgeItemPage $p) => ($several ? "[Página {$p->page}]\n" : '').trim($p->text))->implode("\n\n");
            $sources = $pages->pluck('source')->unique()->values();

            $item->forceFill([
                'content' => mb_substr($content, 0, self::CONTENT_MAX_CHARS),
                'text_source' => match (true) {
                    $sources->isEmpty() => 'none',
                    $sources->count() > 1 => 'mixed',
                    default => (string) $sources->first(),
                },
            ]);
        } else {
            $item->forceFill(['text_source' => trim($item->content) === '' ? 'none' : 'text']);
        }

        $item->forceFill(['reading_status' => KnowledgeItem::READING_DONE])->save();
    }

    public function fail(KnowledgeItem $item, string $note): void
    {
        $item->forceFill(['reading_status' => KnowledgeItem::READING_FAILED, 'reading_note' => Str::limit($note, 250)])->save();
    }

    public function note(KnowledgeItem $item, string $note): void
    {
        $item->forceFill(['reading_note' => Str::limit($note, 250)])->save();
    }

    /**
     * Runs the callback with a local path to the file, copying it from a
     * remote disk when needed.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T|null
     */
    public function withLocalCopy(KnowledgeItem $item, callable $callback): mixed
    {
        $disk = Storage::disk($item->disk ?: KnowledgeBase::DISK);

        if ($item->path === null || ! $disk->exists($item->path)) {
            $this->fail($item, 'O ficheiro já não existe no armazenamento.');

            return null;
        }

        try {
            $local = $disk->path($item->path);
        } catch (Throwable) {
            $local = null; // A disk without local paths (S3): copy below.
        }

        if ($local !== null && is_file($local)) {
            return $callback($local);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'kb').'.'.Str::lower(pathinfo((string) $item->filename, PATHINFO_EXTENSION));
        file_put_contents($tmp, (string) $disk->get($item->path));

        try {
            return $callback($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    private function thumbnail(KnowledgeItem $item, string $path): void
    {
        $png = $this->pdf->thumbnail($path);

        if ($png === null) {
            return;
        }

        $target = 'knowledge/thumbs/'.Str::uuid().'.png';
        Storage::disk($item->disk ?: KnowledgeBase::DISK)->put($target, (string) file_get_contents($png));
        @unlink($png);

        if ($item->thumbnail_path !== null) {
            Storage::disk($item->disk ?: KnowledgeBase::DISK)->delete($item->thumbnail_path);
        }

        $item->forceFill(['thumbnail_path' => $target])->save();
    }
}
