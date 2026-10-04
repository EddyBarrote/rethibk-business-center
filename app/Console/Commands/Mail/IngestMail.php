<?php

namespace App\Console\Commands\Mail;

use App\Console\Concerns\InteractsWithTenant;
use App\Email\InboundEmailIngestor;
use App\Models\Mailbox;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Feeds a .eml file through the same pipeline as IMAP: useful to test the
 * triage without a real mailbox.
 */
#[Signature('mail:ingest {tenant : Slug do tenant} {mailbox : Endereço da caixa} {file : Ficheiro .eml}')]
#[Description('Injecta um ficheiro .eml numa caixa, como se tivesse chegado por IMAP')]
class IngestMail extends Command
{
    use InteractsWithTenant;

    public function handle(InboundEmailIngestor $ingestor): int
    {
        return $this->asTenant(function () use ($ingestor): int {
            $mailbox = Mailbox::query()->where('address', $this->argument('mailbox'))->first();
            $file = (string) $this->argument('file');

            if ($mailbox === null) {
                $this->components->error('Caixa não encontrada neste tenant.');

                return self::FAILURE;
            }

            if (! is_readable($file)) {
                $this->components->error("Não consigo ler {$file}.");

                return self::FAILURE;
            }

            $message = $ingestor->ingest($mailbox, (string) file_get_contents($file), 'cli:'.basename($file));

            if ($message === null) {
                $this->components->warn('Já existia: duplicado ignorado.');

                return self::SUCCESS;
            }

            $this->components->info("Email #{$message->id} recebido; a triagem segue na fila 'email'.");

            return self::SUCCESS;
        });
    }
}
