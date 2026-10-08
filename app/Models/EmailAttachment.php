<?php

namespace App\Models;

use App\Attachments\StoredFile;
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
 * @property string|null $content_id
 * @property int $size_bytes
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $extracted_text
 * @property string $ocr_status
 */
#[Fillable(['email_message_id', 'filename', 'mime_type', 'content_id', 'size_bytes', 'disk', 'path', 'extracted_text', 'ocr_status'])]
class EmailAttachment extends Model implements StoredFile
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

    public function reference(): string
    {
        return "anexo de email #{$this->id}";
    }

    public function fileName(): string
    {
        return $this->filename;
    }

    public function mimeType(): string
    {
        return $this->mime_type ?? 'application/octet-stream';
    }

    public function sizeBytes(): int
    {
        return $this->size_bytes;
    }

    public function diskName(): string
    {
        return $this->disk ?? (string) config('mail_ingest.disk');
    }

    public function storagePath(): ?string
    {
        return $this->ocr_status === 'discarded' ? null : $this->path;
    }

    public function cachedText(): ?string
    {
        return $this->extracted_text;
    }

    public function textTried(): bool
    {
        return $this->ocr_status !== 'pending';
    }

    public function rememberText(?string $text): void
    {
        $this->forceFill(['extracted_text' => $text, 'ocr_status' => $text === null ? 'skipped' : 'done'])->save();
    }

    public function isExternal(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }
}
