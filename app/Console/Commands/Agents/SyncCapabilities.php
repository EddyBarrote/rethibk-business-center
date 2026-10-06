<?php

namespace App\Console\Commands\Agents;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Console\Concerns\InteractsWithTenant;
use App\Erp\Exceptions\ErpException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('capabilities:sync {tenant : Slug do tenant}')]
#[Description('Actualiza o catálogo de capacidades do tenant: capacidades locais e ferramentas do ERP')]
class SyncCapabilities extends Command
{
    use InteractsWithTenant;

    public function handle(CapabilityCatalog $catalog): int
    {
        return $this->asTenant(function () use ($catalog): int {
            $catalog->syncLocal();
            $this->components->info('Capacidades locais actualizadas.');

            try {
                $count = $catalog->syncErp();
                $this->components->info($count === 1 ? '1 ferramenta do ERP disponível.' : "{$count} ferramentas do ERP disponíveis.");
            } catch (ErpException $e) {
                $this->components->warn('ERP inacessível, capacidades do ERP não actualizadas: '.$e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }
}
