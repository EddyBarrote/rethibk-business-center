<?php

namespace Database\Factories;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAttachment>
 */
class EmailAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => fn () => Tenant::current()->id ?? Tenant::factory(),
            'email_message_id' => EmailMessage::factory(),
            'filename' => 'documento.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'disk' => 'local',
            'path' => null,
            'extracted_text' => 'Texto do anexo.',
            'ocr_status' => 'done',
        ];
    }
}
