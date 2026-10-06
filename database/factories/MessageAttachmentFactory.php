<?php

namespace Database\Factories;

use App\Enums\AttachmentKind;
use App\Models\MessageAttachment;
use App\Models\Task;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MessageAttachment>
 */
class MessageAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'task_id' => Task::factory(),
            'source' => 'upload',
            'kind' => AttachmentKind::Pdf,
            'filename' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'received_bytes' => 1024,
            'status' => MessageAttachment::READY,
            'disk' => 'local',
            'path' => 'attachments/test/'.Str::uuid().'.pdf',
            'text_status' => 'pending',
        ];
    }
}
