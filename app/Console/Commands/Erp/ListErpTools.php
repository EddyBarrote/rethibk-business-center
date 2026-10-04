<?php

namespace App\Console\Commands\Erp;

use App\Console\Concerns\InteractsWithTenant;
use App\Erp\ErpGateway;
use App\Erp\ErpTool;
use App\Erp\Exceptions\ErpException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('erp:tools {tenant : Slug do tenant}')]
#[Description('Lista as ferramentas que o ERP do tenant expõe')]
class ListErpTools extends Command
{
    use InteractsWithTenant;

    public function handle(ErpGateway $gateway): int
    {
        return $this->asTenant(function () use ($gateway): int {
            try {
                $tools = $gateway->tools();
            } catch (ErpException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->table(['Ferramenta', 'Tipo', 'Descrição'], array_map(fn (ErpTool $tool) => [
                $tool->name,
                $tool->readOnly ? 'leitura' : 'escrita',
                $tool->description,
            ], $tools));

            $this->components->info(count($tools).' ferramentas.');

            return self::SUCCESS;
        });
    }
}
