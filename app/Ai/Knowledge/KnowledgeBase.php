<?php

namespace App\Ai\Knowledge;

use App\Enums\KnowledgeType;
use App\Jobs\EmbedKnowledgeItem;
use App\Models\KnowledgeEmbedding;
use App\Models\Agent;
use App\Models\KnowledgeItem;
use App\Models\User;
use App\Support\TextExtractor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * Organisational memory (section 13): ingest → chunk → embed, and search by
 * similarity with a keyword fallback when there are no embeddings (no
 * embeddings provider configured, or the item is still being processed).
 */
final class KnowledgeBase
{
    public const DISK = 'local';

    public function __construct(
        private readonly Chunker $chunker,
        private readonly KnowledgeAccess $access,
        private readonly TextExtractor $extractor,
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
     * Keeps a file (upload or generated document) with its extracted text,
     * so people can download it and agents can search it. A file with no
     * readable text is still kept, indexed by its title.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function rememberFile(string $localPath, string $filename, ?string $mime, string $title, ?Model $author, array $attributes = []): KnowledgeItem
    {
        $extension = Str::lower(pathinfo($filename, PATHINFO_EXTENSION));
        $path = 'knowledge/'.Str::uuid().($extension !== '' ? '.'.$extension : '');
        $stream = fopen($localPath, 'r');
        Storage::disk(self::DISK)->put($path, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        // Generated documents pass their Markdown source, which reads better than extracted text.
        $text = $attributes['content'] ?? $this->extractor->extract($localPath, $mime, $filename);
        unset($attributes['content']);

        return $this->remember(KnowledgeType::Document, $title, (string) $text, $author, [
            'disk' => self::DISK,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mime,
            'size_bytes' => filesize($localPath) ?: null,
            ...$attributes,
        ]);
    }

    /**
     * Removes an item, its vectors and its file.
     */
    public function forget(KnowledgeItem $item): void
    {
        if ($item->path !== null) {
            Storage::disk($item->disk ?: self::DISK)->delete($item->path);
        }

        $item->embeddings()->delete();
        $item->delete();
    }

    public function embed(KnowledgeItem $item): void
    {
        $chunks = $this->chunker->split($item->title."\n\n".$item->content);

        if ($chunks === [] || ! self::embeddingsConfigured()) {
            $item->forceFill(['embedding_status' => 'skipped'])->save();

            return;
        }

        try {
            $response = Embeddings::for($chunks)->generate();
        } catch (Throwable $e) {
            report($e);
            $item->forceFill(['embedding_status' => 'failed'])->save();

            return;
        }

        $item->embeddings()->delete();

        foreach ($response->embeddings as $index => $vector) {
            KnowledgeEmbedding::query()->create([
                'knowledge_item_id' => $item->id,
                'chunk_index' => $index,
                'chunk_text' => $chunks[$index],
                'embedding' => array_map('floatval', $vector),
                'model' => $response->meta->model ?? 'unknown',
                'dimensions' => count($vector),
            ]);
        }

        $item->forceFill(['embedding_status' => 'done'])->save();
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
     * one given, every published item.
     *
     * @return Collection<int, array{item: KnowledgeItem, excerpt: string, score: float}>
     */
    public function search(string $query, int $limit = 5, User|Agent|null $viewer = null, ?int $domainId = null): Collection
    {
        $scope = $this->access->items($viewer)->when($domainId, fn (Builder $q, int $id) => $q->where('knowledge_domain_id', $id));
        $semantic = self::embeddingsConfigured() && KnowledgeEmbedding::query()->exists() ? $this->semantic($query, $limit, clone $scope) : collect();

        return $semantic->isNotEmpty() ? $semantic : $this->keyword($query, $limit, $scope);
    }

    /**
     * @param  Builder<KnowledgeItem>  $scope
     * @return Collection<int, array{item: KnowledgeItem, excerpt: string, score: float}>
     */
    private function semantic(string $query, int $limit, Builder $scope): Collection
    {
        $allowed = $scope->pluck('id')->all();

        if ($allowed === []) {
            return collect();
        }

        try {
            $vector = Embeddings::for([$query])->generate()->first();
        } catch (Throwable) {
            return collect();
        }

        $best = [];

        // Brute force over the tenant's chunks: fine for tens of thousands of
        // chunks (section 5.9). MySQL 9 VECTOR can replace this later.
        KnowledgeEmbedding::query()->whereIn('knowledge_item_id', $allowed)->select(['id', 'knowledge_item_id', 'chunk_text', 'embedding'])->chunkById(500, function ($rows) use ($vector, &$best) {
            foreach ($rows as $row) {
                $score = $this->cosine($vector, $row->embedding);
                $current = $best[$row->knowledge_item_id] ?? null;

                if ($current === null || $score > $current['score']) {
                    $best[$row->knowledge_item_id] = ['score' => $score, 'excerpt' => $row->chunk_text];
                }
            }
        });

        uasort($best, fn ($a, $b) => $b['score'] <=> $a['score']);
        $best = array_slice($best, 0, $limit, true);
        $items = KnowledgeItem::query()->whereIn('id', array_keys($best))->get()->keyBy('id');

        $hits = [];

        foreach ($best as $id => $hit) {
            if ($item = $items->get($id)) {
                $hits[] = $this->hit($item, $hit['excerpt'], round($hit['score'], 4));
            }
        }

        return collect($hits);
    }

    /**
     * @param  Builder<KnowledgeItem>  $scope
     * @return Collection<int, array{item: KnowledgeItem, excerpt: string, score: float}>
     */
    private function keyword(string $query, int $limit, Builder $scope): Collection
    {
        $terms = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim($query))) ?: [], fn ($t) => mb_strlen($t) >= 3));

        if ($terms === []) {
            return collect();
        }

        return $scope
            ->where(function ($q) use ($terms) {
                foreach ($terms as $term) {
                    $q->orWhereRaw('LOWER(title) LIKE ?', ["%{$term}%"])->orWhereRaw('LOWER(content) LIKE ?', ["%{$term}%"]);
                }
            })
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (KnowledgeItem $item) => $this->hit($item, mb_substr($item->content, 0, 600), 0));
    }

    /**
     * @return array{item: KnowledgeItem, excerpt: string, score: float}
     */
    private function hit(KnowledgeItem $item, string $excerpt, float $score): array
    {
        return ['item' => $item, 'excerpt' => $excerpt, 'score' => $score];
    }

    /**
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    private function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] ** 2;
            $nb += $b[$i] ** 2;
        }

        return $na > 0 && $nb > 0 ? $dot / (sqrt($na) * sqrt($nb)) : 0.0;
    }
}
