<?php

namespace App\Console\Commands\Agents;

use App\Ai\Skills\SkillCatalog;
use App\Console\Concerns\InteractsWithTenant;
use App\Erp\Exceptions\ErpException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('skills:sync {tenant : Slug do tenant}')]
#[Description('Actualiza o catálogo de skills do tenant: skills locais e ferramentas do ERP')]
class SyncSkills extends Command
{
    use InteractsWithTenant;

    public function handle(SkillCatalog $catalog): int
    {
        return $this->asTenant(function () use ($catalog): int {
            $catalog->syncLocal();
            $this->components->info('Skills locais actualizadas.');

            try {
                $count = $catalog->syncErp();
                $this->components->info("{$count} ferramenta(s) do ERP disponível(is).");
            } catch (ErpException $e) {
                $this->components->warn('ERP inacessível, skills do ERP não actualizadas: '.$e->getMessage());

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }
}
