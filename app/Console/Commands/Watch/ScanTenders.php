<?php

namespace App\Console\Commands\Watch;

use App\Console\Concerns\DispatchesRoles;
use App\Models\Tenant;
use App\Models\Tender;
use App\Tenancy\TenantManager;
use App\Tenders\TenderScanner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agents:scan-tenders')]
#[Description('Procura concursos novos nas fontes de cada tenant e passa-os ao agente de triagem (de hora a hora)')]
class ScanTenders extends Command
{
    use DispatchesRoles;

    public function handle(TenantManager $tenants, TenderScanner $scanner): int
    {
        $tenants->eachActive(function (Tenant $tenant) use ($scanner): void {
            $sources = array_filter((array) ($tenant->settings['tender_sources'] ?? []), fn ($s) => is_array($s) && ($s['active'] ?? true) && filled($s['url'] ?? null));
            $new = [];

            foreach ($sources as $source) {
                $result = $scanner->scan($source);
                $new = [...$new, ...$result['found']];

                if ($result['error'] !== null) {
                    $this->components->warn("{$tenant->slug}: {$source['url']}: {$result['error']}");
                }
            }

            $this->components->info("{$tenant->slug}: ".count($new).' concurso(s) novo(s).');

            if ($new !== []) {
                $list = implode("\n", array_map(fn (Tender $t) => "- #{$t->id} {$t->title} ({$t->source}) {$t->url}", $new));
                $this->dispatchRole('triage', "Encontrei concursos novos nas fontes monitorizadas:\n{$list}\n\nPara cada um: lê a página com web.read_page, regista entidade, referência e prazo com tenders.record (tender_id), descarta os que não interessam à organização (status discarded) e cria a lead no ERP para os relevantes. Notifica a chefia dos que têm prazo nos próximos 10 dias.");
            }
        });

        return self::SUCCESS;
    }
}
