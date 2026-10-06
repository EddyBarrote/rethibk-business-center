<?php

namespace App\Jobs;

use App\Email\MailboxSync;
use App\Enums\MailboxStatus;
use App\Models\Mailbox;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Str;
use Throwable;

/**
 * Polls one mailbox over IMAP (docs/DECISOES.md): every folder in step with
 * the server, and the new email of the watched folder to the agents.
 */
class FetchMailbox extends TenantAwareJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(int $tenantId, public int $mailboxId)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return (string) $this->mailboxId;
    }

    public function handle(MailboxSync $sync): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);

        if ($mailbox === null || $mailbox->status !== MailboxStatus::Active || blank($mailbox->imap_host)) {
            return;
        }

        try {
            $sync->run($mailbox);

            if ($mailbox->last_error !== null) {
                $mailbox->forceFill(['last_error' => null])->save();
            }
        } catch (Throwable $e) {
            $mailbox->forceFill(['last_error' => 'IMAP: '.Str::limit($e->getMessage(), 400)])->save();
            report($e);
        }
    }
}
