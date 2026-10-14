<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\KnowledgeItemPageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The text of one page of a file in the repository: what pdftotext found, or
 * what a model read on a page with no text (a scan).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $knowledge_item_id
 * @property int $page
 * @property string $text
 * @property string $source
 */
#[Fillable(['knowledge_item_id', 'page', 'text', 'source'])]
class KnowledgeItemPage extends Model
{
    /** @use HasFactory<KnowledgeItemPageFactory> */
    use BelongsToTenant, HasFactory;

    public const FROM_TEXT = 'text';

    public const FROM_MODEL = 'model';

    /**
     * @return BelongsTo<KnowledgeItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(KnowledgeItem::class, 'knowledge_item_id');
    }
}
