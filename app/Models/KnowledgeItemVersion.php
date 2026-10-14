<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\KnowledgeItemVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An earlier version of a file in the repository, kept when someone uploads
 * a new one. Only the current version is read and searched.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $knowledge_item_id
 * @property int $version
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property string|null $content_hash
 * @property string $created_by_type
 * @property int|null $created_by_id
 * @property Carbon $created_at
 */
#[Fillable(['knowledge_item_id', 'version', 'disk', 'path', 'filename', 'mime_type', 'size_bytes', 'content_hash', 'created_by_type', 'created_by_id', 'created_at'])]
class KnowledgeItemVersion extends Model
{
    /** @use HasFactory<KnowledgeItemVersionFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<KnowledgeItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }
}
