<?php

namespace App\Console\Commands\Mail;

use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Retention (docs/DECISOES.md): raw messages and attachment files older than
 * the tenant's limit are deleted; the message record, text and triage stay.
 */
#[Signature('mail:prune')]
#[Description('Apaga o email bruto e os anexos mais antigos do que a retenção de cada tenant')]
class PruneMail extends Command
{
    public function handle(TenantManager $tenants): int
    {
        $total = 0;

        $tenants->eachActive(function (Tenant $tenant) use (&$total): void {
            $days = (int) ($tenant->settings['email_retention_days'] ?? config('mail_ingest.retention_days'));
            $cutoff = now()->subDays(max($days, 7));
            $disk = Storage::disk((string) config('mail_ingest.disk'));

            EmailMessage::query()->where('received_at', '<', $cutoff)->whereNotNull('raw_path')->chunkById(200, function ($messages) use ($disk, &$total) {
                foreach ($messages as $message) {
                    $disk->delete((string) $message->raw_path);
                    $message->forceFill(['raw_path' => null])->save();

                    $message->attachments()->whereNotNull('path')->each(function (EmailAttachment $attachment) use ($disk) {
                        $disk->delete((string) $attachment->path);
                        $attachment->forceFill(['path' => null])->save();
                    });

                    $total++;
                }
            });
        });

        $this->components->info("{$total} email(s) sem bruto nem anexos.");

        return self::SUCCESS;
    }
}
