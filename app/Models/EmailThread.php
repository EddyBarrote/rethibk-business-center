<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\EmailThreadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $mailbox_id
 * @property string $subject_normalized
 * @property Carbon|null $last_message_at
 * @property int $message_count
 */
#[Fillable(['mailbox_id', 'subject_normalized', 'last_message_at', 'message_count'])]
class EmailThread extends Model
{
    /** @use HasFactory<EmailThreadFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return HasMany<EmailMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class, 'thread_id')->orderBy('received_at')->orderBy('id');
    }

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'message_count' => 'integer'];
    }
}
