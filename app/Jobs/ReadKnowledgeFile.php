<?php

namespace App\Jobs;

use App\Ai\Knowledge\FileReader;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Knowledge\PageReader;
use App\Models\KnowledgeItem;
use App\Tenancy\TenantAwareJob;
use App\Tenancy\TenantManager;
use Throwable;

/**
 * Reads a file of the repository (FileReader) and then indexes it. The pages
 * without text go to the model a few at a time, each batch its own job, so a
 * long scan never hits the worker's time limit.
 */
class ReadKnowledgeFile extends TenantAwareJob
{
    public int $tries = 2;

    public int $timeout = 290;

    /**
     * @param  list<int>|null  $modelPages  null on the first pass
     */
    public function __construct(int $tenantId, public int $itemId, public ?array $modelPages = null, public int $batch = 0)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(FileReader $reader, KnowledgeBase $knowledge): void
    {
        $item = KnowledgeItem::query()->find($this->itemId);

        if ($item === null || ! $item->isFile()) {
            return;
        }

        if ($this->modelPages === null) {
            $pending = $reader->start($item);

            if ($item->reading_status === KnowledgeItem::READING_FAILED) {
                return;
            }

            if ($pending !== [] && ! PageReader::available()) {
                $reader->note($item, count($pending) === 1 && $item->isImage()
                    ? 'Imagem sem texto legível: não há modelo de IA que leia imagens, ou o orçamento do mês acabou.'
                    : count($pending).' páginas sem texto (digitalizadas) ficaram por ler: não há modelo de IA que leia PDF, ou o orçamento do mês acabou.');
                $pending = [];
            }

            $pending === [] ? $this->done($reader, $knowledge, $item) : self::dispatch($this->tenantId, $item->id, $pending, 0)->onQueue('embeddings');

            return;
        }

        $size = max(1, (int) config('knowledge.reading.batch_pages', 8));
        $pages = array_slice($this->modelPages, $this->batch * $size, $size);

        if ($pages !== [] && PageReader::available()) {
            try {
                $reader->readWithModel($item, $pages);
            } catch (Throwable $e) {
                report($e);
                $reader->note($item, 'O modelo não conseguiu ler as páginas '.implode(', ', $pages).': '.$e->getMessage());
            }
        } elseif ($pages !== []) {
            $left = count($this->modelPages) - $this->batch * $size;
            $reader->note($item, "{$left} páginas digitalizadas ficaram por ler: o orçamento de IA do mês acabou.");
        }

        $more = ($this->batch + 1) * $size < count($this->modelPages) && PageReader::available();

        $more ? self::dispatch($this->tenantId, $item->id, $this->modelPages, $this->batch + 1)->onQueue('embeddings') : $this->done($reader, $knowledge, $item);
    }

    public function failed(?Throwable $e): void
    {
        app(TenantManager::class)->set($this->tenantId);
        $item = KnowledgeItem::query()->find($this->itemId);

        if ($item !== null) {
            app(FileReader::class)->fail($item, 'A leitura falhou: '.($e?->getMessage() ?? 'interrompida'));
            app(KnowledgeBase::class)->embed($item);
        }
    }

    private function done(FileReader $reader, KnowledgeBase $knowledge, KnowledgeItem $item): void
    {
        $reader->finish($item);
        $knowledge->embed($item->refresh());
    }
}
