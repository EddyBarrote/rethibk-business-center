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
 * The agent drafts that went to the Rascunhos by themselves, before "only
 * when the person chooses" (docs/DECISOES.md, "Rascunho só quando a pessoa
 * escolhe"): those no person kept or changed go back to proposals, out of the
 * Rascunhos and off the server, the row kept. A person's draft is never touched.
 */
#[Signature('mail:return-agent-drafts {--dry-run : Só lista o que devolveria}')]
#[Description('Devolve a propostas os rascunhos de agentes que nenhuma pessoa guardou ou alterou')]
class ReturnAgentDrafts extends Command
{
    public function handle(TenantManager $tenants, AgentDrafts $drafts, MailClient $client): int
    {
        $dry = (bool) $this->option('dry-run');

        $tenants->eachActive(function (Tenant $tenant) use ($drafts, $client, $dry): void {
            $filed = $drafts->filedProposals();

            $filed->each(function (EmailMessage $draft) use ($drafts, $client, $dry, $tenant) {
                $this->line("{$tenant->slug}: #{$draft->id} «{$draft->subject}»".($dry ? '' : ' devolvido a proposta'));

                if (! $dry) {
                    $drafts->returnToProposal($draft, $client);
                }
            });

            $this->components->info("{$tenant->slug}: {$filed->count()} rascunho(s) de agentes".($dry ? ' por devolver.' : ' devolvido(s) a proposta.'));
        });

        return self::SUCCESS;
    }
}
