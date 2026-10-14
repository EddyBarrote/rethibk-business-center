<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\KnowledgeEmbeddingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $knowledge_item_id
 * @property int $chunk_index
 * @property string $chunk_text
 * @property int|null $page
 * @property list<float>|null $embedding
 * @property string|null $vector float32, normalised (VectorCodec)
 * @property string $model
 * @property int $dimensions
 */
#[Fillable(['knowledge_item_id', 'chunk_index', 'page', 'chunk_text', 'embedding', 'vector', 'model', 'dimensions'])]
class KnowledgeEmbedding extends Model
{
    /** @use HasFactory<KnowledgeEmbeddingFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<KnowledgeItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }
}
