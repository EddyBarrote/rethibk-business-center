<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\TenderStatus;
use Database\Factories\TenderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A public tender found on a monitored source or in an email (E03).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $source
 * @property string|null $reference
 * @property string $title
 * @property string|null $entity
 * @property string|null $url
 * @property string|null $url_hash
 * @property string|null $summary
 * @property Carbon|null $deadline_at
 * @property TenderStatus $status
 * @property int|null $email_message_id
 * @property string|null $erp_lead_id
 * @property list<string>|null $matched_keywords
 * @property Carbon $created_at
 */
#[Fillable(['source', 'reference', 'title', 'entity', 'url', 'url_hash', 'summary', 'deadline_at', 'status', 'email_message_id', 'erp_lead_id', 'matched_keywords'])]
class Tender extends Model
{
    /** @use HasFactory<TenderFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function email(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    protected function casts(): array
    {
        return [
            'status' => TenderStatus::class,
            'deadline_at' => 'datetime',
            'matched_keywords' => 'array',
        ];
    }
}
