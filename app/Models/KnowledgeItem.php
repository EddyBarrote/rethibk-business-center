<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\KnowledgeType;
use Database\Factories\KnowledgeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Organisational memory (section 13).
 *
 * @property int $id
 * @property int $tenant_id
 * @property KnowledgeType $type
 * @property string $title
 * @property string $content
 * @property string|null $summary
 * @property bool $is_external
 * @property int|null $department_id
 * @property string $visibility
 * @property string $created_by_type
 * @property int|null $created_by_id
 * @property string $embedding_status
 * @property Carbon $created_at
 */
#[Fillable(['type', 'title', 'content', 'summary', 'source_type', 'source_id', 'is_external', 'department_id', 'visibility', 'created_by_type', 'created_by_id', 'embedding_status'])]
class KnowledgeItem extends Model
{
    /** @use HasFactory<KnowledgeItemFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return HasMany<KnowledgeEmbedding, $this>
     */
    public function embeddings(): HasMany
    {
        return $this->hasMany(KnowledgeEmbedding::class)->orderBy('chunk_index');
    }

    protected function casts(): array
    {
        return [
            'type' => KnowledgeType::class,
            'is_external' => 'boolean',
        ];
    }
}
