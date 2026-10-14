<?php

namespace App\Ai\Knowledge;

use App\Enums\KnowledgeType;
use App\Jobs\EmbedKnowledgeItem;
use App\Jobs\ReadKnowledgeFile;
use App\Models\Agent;
use App\Models\KnowledgeEmbedding;
use App\Models\KnowledgeItem;
use App\Models\KnowledgeItemVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Organisational memory (section 13) and the company's file repository
 * (docs/DECISOES.md, 14.10.2026): ingest → read (files, on the queue) →
 * chunk with pages → embed, and a hybrid search that joins similarity of
 * vectors with exact and full-text matches, so a question finds what it means
 * and a reference ("FT-2026-118", a NUIT) finds the document that has it.
 */
final class KnowledgeBase
{
    public const DISK = 'local';

    /** Reciprocal rank fusion constant: how much a top rank outweighs the next. */
    private const RRF_K = 60;

    public function __construct(
        private readonly Chunker $chunker,
        private readonly KnowledgeAccess $access,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function remember(KnowledgeType $type, string $title, string $content, ?Model $author, array $attributes = []): KnowledgeItem
    {
        $item = KnowledgeItem::query()->create([
            'type' => $type,
            'title' => $title,
            'content' => $content,
            'created_by_type' => $author === null ? 'system' : $author->getMorphClass(),
            'created_by_id' => $author?->getKey(),
            ...$attributes,
        ]);

        EmbedKnowledgeItem::dispatch($item->tenant_id, $item->id)->onQueue('embeddings');

        return $item;
    }

    /**
     * Keeps a file (upload, attachment or generated document). Its text is
     * read on the queue (ReadKnowledgeFile), page by page, and then indexed;
     * a file with no readable text is still kept, found by its title.
     * Generated documents pass their Markdown source as `content`, which
     * reads better than the text taken from the file.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function rememberFile(string $localPath, string $filename, ?string $mime, string $title, ?Model $author, array $attributes = []): KnowledgeItem
    {
        $stored = $this->store($localPath, $filename);

        $item = KnowledgeItem::query()->create([
            'type' => KnowledgeType::Document,
            'title' => $title,
            'content' => (string) ($attributes['content'] ?? ''),
            'created_by_type' => $author === null ? 'system' : $author->getMorphClass(),
            'created_by_id' => $author?->getKey(),
            'disk' => self::DISK,
            'path' => $stored,
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => filesize($localPath) ?: null,
            'content_hash' => hash_file('sha256', $localPath) ?: null,
            'reading_status' => KnowledgeItem::READING_PENDING,
            ...array_diff_key($attributes, ['content' => true]),
        ]);

        ReadKnowledgeFile::dispatch($item->tenant_id, $item->id)->onQueue('embeddings');

        return $item;
    }

    /**
     * A new version of a file: the current one is kept in its history, the
     * new one is read and indexed in its place.
     */
    public function replaceFile(KnowledgeItem $item, string $localPath, string $filename, ?string $mime, ?Model $author): KnowledgeItem
    {
        KnowledgeItemVersion::query()->create([
            'knowledge_item_id' => $item->id,
            'version' => $item->version,
            'disk' => $item->disk ?: self::DISK,
            'path' => (string) $item->path,
            'filename' => (string) $item->filename,
            'mime_type' => $item->mime_type,
            'size_bytes' => $item->size_bytes,
            'content_hash' => $item->content_hash,
            'created_by_type' => $item->created_by_type,
            'created_by_id' => $item->created_by_id,
            'created_at' => $item->updated_at,
        ]);

        if ($item->thumbnail_path !== null) {
            Storage::disk($item->disk ?: self::DISK)->delete($item->thumbnail_path);
        }

        $item->pageTexts()->delete();
        $item->forceFill([
            'disk' => self::DISK,
            'path' => $this->store($localPath, $filename),
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => filesize($localPath) ?: null,
            'content_hash' => hash_file('sha256', $localPath) ?: null,
            'content' => '',
            'pages' => null,
            'thumbnail_path' => null,
            'text_source' => null,
            'reading_note' => null,
            'reading_status' => KnowledgeItem::READING_PENDING,
            'embedding_status' => 'pending',
            'version' => $item->version + 1,
            // Whoever uploads the version becomes its author; the earlier author stays on the old version.
            'created_by_type' => $author === null ? 'system' : $author->getMorphClass(),
            'created_by_id' => $author?->getKey(),
            // A file from outside the company is still from outside, whoever replaced it.
            'source_type' => null,
            'source_id' => null,
        ])->save();

        ReadKnowledgeFile::dispatch($item->tenant_id, $item->id)->onQueue('embeddings');

        return $item;
    }

    /**
     * Reads a file again (a scan read before there was a model, a failure).
     */
    public function reread(KnowledgeItem $item): void
    {
        $item->forceFill(['reading_status' => KnowledgeItem::READING_PENDING, 'reading_note' => null, 'embedding_status' => 'pending'])->save();
        ReadKnowledgeFile::dispatch($item->tenant_id, $item->id)->onQueue('embeddings');
    }

    /**
     * Removes an item, its vectors, its file and its earlier versions.
     */
    public function forget(KnowledgeItem $item): void
    {
        $disk = Storage::disk($item->disk ?: self::DISK);

        foreach ([$item->path, $item->thumbnail_path] as $path) {
            if ($path !== null) {
                $disk->delete($path);
            }
        }

        foreach ($item->versions()->get() as $version) {
            Storage::disk($version->disk)->delete($version->path);
        }

        $item->embeddings()->delete();
        $item->delete();
    }

    public function embed(KnowledgeItem $item): void
    {
        $pieces = $this->chunker->splitWithPages($item->title."\n\n".$item->content);

        if ($pieces === [] || ! self::embeddingsConfigured()) {
            $item->forceFill(['embedding_status' => 'skipped'])->save();

            return;
        }

        $dimensions = (int) config('knowledge.embedding_dimensions', 768);
        $rows = [];

        try {
            foreach (array_chunk($pieces, max(1, (int) config('knowledge.embedding_batch', 50))) as $batch) {
                $response = Embeddings::for(array_column($batch, 'text'))->dimensions($dimensions)->generate();

                foreach ($response->embeddings as $index => $vector) {
                    $rows[] = [
                        'chunk_index' => count($rows),
                        'page' => $batch[$index]['page'],
                        'chunk_text' => $batch[$index]['text'],
                        'vector' => VectorCodec::pack(array_map('floatval', $vector)),
                        'model' => $response->meta->model ?? 'unknown',
                        'dimensions' => count($vector),
                    ];
                }
            }
        } catch (Throwable $e) {
            report($e);
            $item->forceFill(['embedding_status' => 'failed'])->save();

            return;
        }

        DB::transaction(function () use ($item, $rows) {
            $item->embeddings()->delete();

            foreach ($rows as $row) {
                KnowledgeEmbedding::query()->create(['knowledge_item_id' => $item->id, 'embedding' => null, ...$row]);
            }

            $item->forceFill(['embedding_status' => 'done'])->save();
        });
    }

    /**
     * Without an embeddings provider key, memory still works by keyword.
     */
    public static function embeddingsConfigured(): bool
    {
        $provider = (string) config('ai.default_for_embeddings');

        return Embeddings::isFaked() || filled(config("ai.providers.{$provider}.key")) || $provider === 'ollama';
    }

    /**
     * Searches what the person or agent may read (KnowledgeAccess); with no
     * one given, every published item. Vectors and words both vote (reciprocal
     * rank fusion); an item that has the exact words of the query comes first.
     *
     * @return Collection<int, array{item: KnowledgeItem, excerpt: string, score: float, page: int|null}>
     */
    public function search(string $query, int $limit = 5, User|Agent|null $viewer = null, ?int $domainId = null): Collection
    {
        $query = trim($query);
        $scope = $this->access->items($viewer)->when($domainId, fn (Builder $q, int $id) => $q->where('knowledge_domain_id', $id));
        $wide = max($limit * 2, 10);
        $semantic = self::embeddingsConfigured() && KnowledgeEmbedding::query()->exists() ? $this->semantic($query, $wide, clone $scope) : [];
        $words = $this->keyword($query, $wide, clone $scope);

        $fused = [];

        foreach ([$semantic, $words] as $list) {
            foreach ($list as $rank => $hit) {
                $id = $hit['item']->id;
                $fused[$id] ??= ['item' => $hit['item'], 'rrf' => 0.0, 'exact' => false, 'score' => 0.0, 'excerpt' => $hit['excerpt'], 'page' => $hit['page']];
                $fused[$id]['rrf'] += 1 / (self::RRF_K + $rank + 1);
                $fused[$id]['score'] = max($fused[$id]['score'], $hit['score']);

                // The words found show better than a similar paragraph: an exact match shows where the words are.
                if ($hit['exact'] ?? false) {
                    $fused[$id]['exact'] = true;
                    $fused[$id]['excerpt'] = $hit['excerpt'];
                    $fused[$id]['page'] = $hit['page'];
                }
            }
        }

        uasort($fused, fn (array $a, array $b) => [$b['exact'], $b['rrf']] <=> [$a['exact'], $a['rrf']]);

        return collect(array_slice(array_values($fused), 0, $limit))
            ->map(fn (array $hit) => $this->hit($hit['item'], $hit['excerpt'], round($hit['score'], 4), $hit['page']));
    }

    /**
     * Every chunk the viewer may read, compared with the query's vector. The
     * vectors are normalised, so the dot product is the cosine.
     *
     * @param  Builder<KnowledgeItem>  $scope
     * @return list<array{item: KnowledgeItem, excerpt: string, score: float, page: int|null}>
     */
    private function semantic(string $query, int $limit, Builder $scope): array
    {
        $allowed = $scope->pluck('id')->all();

        if ($allowed === [] || $query === '') {
            return [];
        }

        try {
            $vector = VectorCodec::normalise(array_map('floatval', Embeddings::for([$query])->dimensions((int) config('knowledge.embedding_dimensions', 768))->generate()->first()));
        } catch (Throwable) {
            return [];
        }

        $best = [];

        // Brute force over the tenant's chunks (section 5.9), now on 768
        // float32 per chunk (3 KB) instead of 3072 JSON numbers; fine for tens
        // of thousands of chunks. A database with a vector index can replace this.
        KnowledgeEmbedding::query()
            ->whereIn('knowledge_item_id', $allowed)
            ->whereNotNull('vector')
            ->where('dimensions', count($vector))
            ->select(['id', 'knowledge_item_id', 'page', 'chunk_text', 'vector'])
            ->chunkById(1000, function ($rows) use ($vector, &$best) {
                foreach ($rows as $row) {
                    $score = VectorCodec::dot($vector, VectorCodec::unpack((string) $row->vector));
                    $current = $best[$row->knowledge_item_id] ?? null;

                    if ($current === null || $score > $current['score']) {
                        $best[$row->knowledge_item_id] = ['score' => $score, 'excerpt' => $row->chunk_text, 'page' => $row->page];
                    }
                }
            });

        uasort($best, fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = array_slice($best, 0, $limit, true);
        $items = KnowledgeItem::query()->whereIn('id', array_keys($best))->get()->keyBy('id');
        $hits = [];

        foreach ($best as $id => $hit) {
            if ($item = $items->get($id)) {
                $hits[] = $this->hit($item, $hit['excerpt'], $hit['score'], $hit['page']);
            }
        }

        return $hits;
    }

    /**
     * Items with the exact words of the query first, then a full-text search
     * (MySQL and MariaDB) or, elsewhere, any of the words.
     *
     * @param  Builder<KnowledgeItem>  $scope
     * @return list<array{item: KnowledgeItem, excerpt: string, score: float, page: int|null, exact?: bool}>
     */
    private function keyword(string $query, int $limit, Builder $scope): array
    {
        $phrase = mb_strtolower($query);
        $terms = array_values(array_filter(preg_split('/\s+/', $phrase) ?: [], fn ($t) => mb_strlen($t) >= 3));
        $hits = [];

        if (mb_strlen($phrase) >= 3) {
            (clone $scope)
                ->where(fn (Builder $q) => $q->whereRaw('LOWER(title) LIKE ?', ['%'.$phrase.'%'])->orWhereRaw('LOWER(content) LIKE ?', ['%'.$phrase.'%']))
                ->latest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (KnowledgeItem $item) use (&$hits, $phrase) {
                    [$excerpt, $page] = $this->around($item, [$phrase]);
                    $hits[$item->id] = [...$this->hit($item, $excerpt, 0, $page), 'exact' => true];
                });
        }

        if ($terms === [] || count($hits) >= $limit) {
            return array_values($hits);
        }

        $more = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)
            ? (clone $scope)->whereFullText(['title', 'content'], $query)->orderByRaw('MATCH(title, content) AGAINST (?) DESC', [$query])
            : (clone $scope)->where(function (Builder $q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhereRaw('LOWER(title) LIKE ?', ['%'.$term.'%'])->orWhereRaw('LOWER(content) LIKE ?', ['%'.$term.'%']);
                }
            })->latest('id');

        foreach ($more->whereNotIn('id', array_keys($hits))->limit($limit - count($hits))->get() as $item) {
            [$excerpt, $page] = $this->around($item, $terms);
            $hits[$item->id] = $this->hit($item, $excerpt, 0, $page);
        }

        return array_values($hits);
    }

    /**
     * About 600 characters around the first of these words in the item, and
     * the page they are on.
     *
     * @param  list<string>  $needles
     * @return array{0: string, 1: int|null}
     */
    private function around(KnowledgeItem $item, array $needles): array
    {
        $lower = mb_strtolower($item->content);
        $at = null;

        foreach ($needles as $needle) {
            $found = mb_strpos($lower, $needle);

            if ($found !== false && ($at === null || $found < $at)) {
                $at = $found;
            }
        }

        if ($at === null) {
            return [mb_substr($item->content, 0, 600), self::pageAt($item->content, 0)];
        }

        $start = max(0, $at - 200);

        return [($start > 0 ? '…' : '').mb_substr($item->content, $start, 600), self::pageAt($item->content, $at)];
    }

    /**
     * The page a position of a file's text is on, from its [Página N] lines.
     */
    public static function pageAt(string $content, int $position): ?int
    {
        $before = mb_substr($content, 0, $position + 1);

        if (preg_match_all('/^\[P[áa]gina (\d+)\]/mu', $before, $matches) > 0) {
            return (int) end($matches[1]);
        }

        return preg_match('/^\[P[áa]gina (\d+)\]/mu', $content, $first) === 1 ? (int) $first[1] : null;
    }

    /**
     * @return array{item: KnowledgeItem, excerpt: string, score: float, page: int|null}
     */
    private function hit(KnowledgeItem $item, string $excerpt, float $score, ?int $page = null): array
    {
        return ['item' => $item, 'excerpt' => $excerpt, 'score' => $score, 'page' => $page];
    }

    private function store(string $localPath, string $filename): string
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $path = 'knowledge/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        $stream = fopen($localPath, 'r');
        Storage::disk(self::DISK)->put($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $path;
    }
}
