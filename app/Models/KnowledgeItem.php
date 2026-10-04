<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\KnowledgeType;
use Database\Factories\KnowledgeItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property int|null $knowledge_domain_id
 * @property int|null $knowledge_folder_id
 * @property string $status
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $reviewed_by_user_id
 * @property Carbon|null $reviewed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'type', 'title', 'content', 'summary', 'source_type', 'source_id', 'is_external', 'department_id', 'visibility', 'created_by_type', 'created_by_id',
    'embedding_status', 'knowledge_domain_id', 'knowledge_folder_id', 'status', 'disk', 'path', 'filename', 'mime_type', 'size_bytes',
])]
class KnowledgeItem extends Model
{
    /** @use HasFactory<KnowledgeItemFactory> */
    use BelongsToTenant, HasFactory;

    /** Visible to people and agents. */
    public const PUBLISHED = 'published';

    /** Written by an agent below N3: people see it, agents do not, until someone approves it. */
    public const PENDING_REVIEW = 'pending_review';

    public const REJECTED = 'rejected';

    public function isFile(): bool
    {
        return $this->path !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === self::PUBLISHED;
    }

    /**
     * @return BelongsTo<KnowledgeDomain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(KnowledgeDomain::class, 'knowledge_domain_id');
    }

    /**
     * @return BelongsTo<KnowledgeFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(KnowledgeFolder::class, 'knowledge_folder_id');
    }

    public function writtenByAgent(): bool
    {
        return $this->created_by_type === (new Agent)->getMorphClass();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

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
            'reviewed_at' => 'datetime',
        ];
    }
}
