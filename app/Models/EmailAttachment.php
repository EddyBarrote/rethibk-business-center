<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use Database\Factories\EmailAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property int $email_message_id
 * @property string $filename
 * @property string|null $mime_type
 * @property int $size_bytes
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $extracted_text
 * @property string $ocr_status
 */
#[Fillable(['email_message_id', 'filename', 'mime_type', 'size_bytes', 'disk', 'path', 'extracted_text', 'ocr_status'])]
class EmailAttachment extends Model
{
    /** @use HasFactory<EmailAttachmentFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * @return BelongsTo<EmailMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(EmailMessage::class, 'email_message_id');
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
