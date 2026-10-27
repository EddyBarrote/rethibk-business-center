<?php

namespace App\Jobs;

use App\Email\Acknowledgement;
use App\Models\EmailMessage;
use App\Tenancy\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Sends the «we received it» reply for one email (docs/DECISOES.md,
 * "Confirmação automática de recepção"), apart from the triage, so a failing
 * mail server never stops the agent.
 */
class AcknowledgeEmail extends TenantAwareJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(int $tenantId, public int $emailId)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return (string) $this->emailId;
    }

    public function handle(Acknowledgement $acknowledgement): void
    {
        $email = EmailMessage::query()->with('mailbox')->find($this->emailId);

        if ($email !== null) {
            $acknowledgement->send($email);
        }
    }
}
