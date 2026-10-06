<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\GeneratedDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Word, PowerPoint, Excel or PDF file an agent (or a person) generated
 * from Markdown, with the tenant's branding.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $agent_id
 * @property int|null $agent_run_id
 * @property int|null $created_by_user_id
 * @property string $title
 * @property string $format
 * @property string $template
 * @property string $source
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property int $size_bytes
 * @property int|null $knowledge_item_id
 * @property Carbon $created_at
 */
#[Fillable(['agent_id', 'agent_run_id', 'created_by_user_id', 'title', 'format', 'template', 'source', 'disk', 'path', 'filename', 'size_bytes', 'knowledge_item_id'])]
class GeneratedDocument extends Model
{
    /** @use HasFactory<GeneratedDocumentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<Agent, $this>
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<KnowledgeItem, $this>
     */
    public function knowledgeItem(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class);
    }
}
