<?php

namespace App\Console\Commands\Erp;

use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('erp:call {tenant : Slug do tenant} {tool : Nome da ferramenta, ex. crm.search_accounts} {arguments={} : Argumentos em JSON}')]
#[Description('Chama uma ferramenta do ERP do tenant (fica registada na auditoria)')]
class CallErpTool extends Command
{
    use InteractsWithTenant;

    public function handle(ErpGateway $gateway): int
    {
        $arguments = json_decode((string) $this->argument('arguments'), true);

        if (! is_array($arguments)) {
            $this->components->error('Os argumentos têm de ser um objecto JSON.');

            return self::FAILURE;
        }

        return $this->asTenant(function () use ($gateway, $arguments): int {
            try {
                $result = $gateway->call((string) $this->argument('tool'), $arguments);
            } catch (ErpException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $this->line((string) json_encode($result->data ?? $result->text, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if (! $result->ok) {
                $this->components->error("{$result->tool} respondeu com erro.");

                return self::FAILURE;
            }

            return self::SUCCESS;
        });
    }
}
