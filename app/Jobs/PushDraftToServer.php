<?php

namespace App\Jobs;

use App\Email\MailClient;
use App\Models\EmailMessage;
use App\Tenancy\TenantAwareJob;

/**
 * An agent's draft goes to the owner's Rascunhos on the IMAP server, so the
 * person finds it here and in any other email client (docs/DECISOES.md,
 * "Email: um cliente de email na plataforma").
 */
class PushDraftToServer extends TenantAwareJob
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(int $tenantId, public int $messageId)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('email');
    }

    public function handle(MailClient $client): void
    {
        $draft = EmailMessage::query()->with('mailbox')->find($this->messageId);

        if ($draft !== null) {
            $client->pushDraft($draft);
        }
    }
}
