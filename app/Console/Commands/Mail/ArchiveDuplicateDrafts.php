<?php

namespace App\Console\Commands\Mail;

use App\Email\AgentDrafts;
use App\Email\MailClient;
use App\Models\EmailMessage;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The agent drafts left over from before "one open draft per email"
 * (docs/DECISOES.md, "Um só rascunho por email"): the newest per email stays,
 * the older ones are archived (out of the views and the server's Rascunhos,
 * the row kept). A draft a person wrote or changed is never touched.
 */
#[Signature('mail:archive-duplicate-drafts {--dry-run : Só lista o que arquivaria}')]
#[Description('Arquiva os rascunhos de agentes repetidos para o mesmo email, ficando o mais recente')]
class ArchiveDuplicateDrafts extends Command
{
    public function handle(TenantManager $tenants, AgentDrafts $drafts, MailClient $client): int
    {
        $dry = (bool) $this->option('dry-run');

        $tenants->eachActive(function (Tenant $tenant) use ($drafts, $client, $dry): void {
            $duplicates = $drafts->duplicates();

            $duplicates->each(function (EmailMessage $draft) use ($drafts, $client, $dry, $tenant) {
                $this->line("{$tenant->slug}: #{$draft->id} «{$draft->subject}»".($dry ? '' : ' arquivado'));

                if (! $dry) {
                    $drafts->archive($draft, $client);
                }
            });

            $this->components->info("{$tenant->slug}: {$duplicates->count()} rascunho(s) repetido(s)".($dry ? ' por arquivar.' : ' arquivado(s).'));
        });

        return self::SUCCESS;
    }
}
