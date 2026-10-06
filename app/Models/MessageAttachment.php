<?php

namespace App\Models;

use App\Attachments\StoredFile;
use App\Concerns\BelongsToTenant;
use App\Enums\AttachmentKind;
use Database\Factories\MessageAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file in a conversation or task thread (docs/DECISOES.md, "Anexos nas
 * conversas"): a person's upload or recording, or a file an agent sends.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $task_id
 * @property int|null $task_message_id
 * @property int|null $uploaded_by_user_id
 * @property int|null $agent_id
 * @property int|null $agent_run_id
 * @property string $source
 * @property AttachmentKind $kind
 * @property string $filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $received_bytes
 * @property string $status
 * @property string $disk
 * @property string $path
 * @property string|null $extracted_text
 * @property string $text_status
 * @property Carbon $created_at
 */
#[Fillable(['task_id', 'task_message_id', 'uploaded_by_user_id', 'agent_id', 'agent_run_id', 'source', 'kind', 'filename', 'mime_type', 'size_bytes', 'received_bytes', 'status', 'disk', 'path', 'extracted_text', 'text_status'])]
class MessageAttachment extends Model implements StoredFile
{
    /** @use HasFactory<MessageAttachmentFactory> */
    use BelongsToTenant, HasFactory;

    public const READY = 'ready';

    public const UPLOADING = 'uploading';

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<TaskMessage, $this>
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(TaskMessage::class, 'task_message_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    /**
     * @param  Builder<MessageAttachment>  $query
     */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', self::READY);
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->filename, PATHINFO_EXTENSION));
    }

    public function reference(): string
    {
        return "anexo #{$this->id}";
    }

    public function fileName(): string
    {
        return $this->filename;
    }

    public function mimeType(): string
    {
        return $this->mime_type;
    }

    public function sizeBytes(): int
    {
        return $this->size_bytes;
    }

    public function diskName(): string
    {
        return $this->disk;
    }

    public function storagePath(): ?string
    {
        return $this->path;
    }

    public function cachedText(): ?string
    {
        return $this->extracted_text;
    }

    public function textTried(): bool
    {
        return $this->text_status !== 'pending';
    }

    public function rememberText(?string $text): void
    {
        $this->forceFill(['extracted_text' => $text, 'text_status' => $text === null ? 'skipped' : 'done'])->save();
    }

    public function isExternal(): bool
    {
        return false;
    }

    protected function casts(): array
    {
        return [
            'kind' => AttachmentKind::class,
            'size_bytes' => 'integer',
            'received_bytes' => 'integer',
        ];
    }
}
