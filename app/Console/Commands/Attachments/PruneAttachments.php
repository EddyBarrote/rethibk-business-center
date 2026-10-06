<?php

namespace App\Console\Commands\Attachments;

use App\Attachments\AttachmentStore;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Files uploaded in a conversation and never sent (docs/DECISOES.md, "Anexos
 * nas conversas").
 */
#[Signature('attachments:prune')]
#[Description('Apaga os ficheiros carregados nas conversas e nunca enviados')]
class PruneAttachments extends Command
{
    public function handle(TenantManager $tenants, AttachmentStore $store): int
    {
        $total = 0;

        $tenants->eachActive(function (Tenant $tenant) use ($store, &$total): void {
            $total += $store->prune();
        });

        $this->components->info("{$total} ficheiro(s) por enviar apagado(s).");

        return self::SUCCESS;
    }
}
