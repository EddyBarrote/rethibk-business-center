<?php

namespace App\Console\Commands\Knowledge;

use App\Ai\Knowledge\KnowledgeBase;
use App\Jobs\EmbedKnowledgeItem;
use App\Models\KnowledgeItem;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * After the move to the file repository (docs/DECISOES.md, 14.10.2026): files
 * are read again page by page (the scanned pages by a model) and everything
 * is indexed again with the smaller binary vectors. Also for one file whose
 * reading failed, or after a model key is added.
 */
#[Signature('knowledge:reindex {--item= : Só este item} {--unread : Só os ficheiros ainda não lidos página a página}')]
#[Description('Volta a ler os ficheiros do Conhecimento e a indexar tudo com os vectores novos')]
class Reindex extends Command
{
    public function handle(TenantManager $tenants, KnowledgeBase $knowledge): int
    {
        $files = 0;
        $articles = 0;

        $tenants->eachActive(function () use ($tenants, $knowledge, &$files, &$articles): void {
            KnowledgeItem::query()
                ->when($this->option('item'), fn ($q, $id) => $q->whereKey((int) $id))
                ->when($this->option('unread'), fn ($q) => $q->whereNotNull('path')->whereNull('reading_status'))
                ->orderBy('id')
                ->each(function (KnowledgeItem $item) use ($tenants, $knowledge, &$files, &$articles): void {
                    if ($item->isFile()) {
                        $knowledge->reread($item);
                        $files++;
                    } else {
                        EmbedKnowledgeItem::dispatch((int) $tenants->id(), $item->id)->onQueue('embeddings');
                        $articles++;
                    }
                });
        });

        $this->components->info("{$files} ficheiro(s) a ler e {$articles} artigo(s) a indexar, na fila.");

        return self::SUCCESS;
    }
}
