<?php

namespace App\Jobs;

use App\Email\InboundEmailIngestor;
use App\Email\MailboxFetcher;
use App\Enums\MailboxStatus;
use App\Models\Mailbox;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls one mailbox over IMAP (docs/DECISOES.md) and ingests what is new.
 */
class FetchMailbox extends TenantAwareJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(int $tenantId, public int $mailboxId)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    public function handle(MailboxFetcher $fetcher, InboundEmailIngestor $ingestor): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if ($mailbox === null || $mailbox->status !== MailboxStatus::Active || blank($mailbox->imap_host)) {
            return;
        }

        try {
            foreach ($fetcher->fetch($mailbox, $mailbox->imap_last_uid, (int) config('mail_ingest.batch_size')) as $item) {
                $ingestor->ingest($mailbox, $item['raw'], 'imap:'.$item['uid']);
                // Advance after each message, so a failure never re-reads what was done.
                $mailbox->forceFill(['imap_last_uid' => max((int) $mailbox->imap_last_uid, $item['uid'])])->save();
            }

            if ($mailbox->last_error !== null) {
                $mailbox->forceFill(['last_error' => null])->save();
            }
        } catch (Throwable $e) {
            $mailbox->forceFill(['last_error' => 'IMAP: '.Str::limit($e->getMessage(), 400)])->save();
            report($e);
        }
    }
}
