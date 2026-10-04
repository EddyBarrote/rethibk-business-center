<?php

namespace App\Console\Commands\Mail;

use App\Enums\MailboxStatus;
use App\Jobs\FetchMailbox;
use App\Models\Mailbox;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('mail:fetch')]
#[Description('Lê por IMAP as caixas activas de todos os tenants (agendado a cada minuto)')]
class FetchMail extends Command
{
    public function handle(TenantManager $tenants): int
    {
        $count = 0;

        $tenants->eachActive(function () use (&$count): void {
            Mailbox::query()->where('status', MailboxStatus::Active)->whereNotNull('imap_host')->each(function (Mailbox $mailbox) use (&$count): void {
                FetchMailbox::dispatch($mailbox->tenant_id, $mailbox->id);
                $count++;
            });
        });

        $this->components->info("{$count} caixa(s) em leitura.");

        return self::SUCCESS;
    }
}
