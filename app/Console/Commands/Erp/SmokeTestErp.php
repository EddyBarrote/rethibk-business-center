<?php

namespace App\Console\Commands\Erp;

use App\Console\Concerns\InteractsWithTenant;
use App\Erp\ErpGateway;
use App\Erp\Exceptions\ErpException;
use App\Models\AuditLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * The E01 acceptance check (section 19): list the ERP tools, call one read and
 * one write, and show both calls in audit_logs.
 */
#[Signature('erp:smoke {tenant : Slug do tenant}')]
#[Description('Prova da E01: lista as ferramentas, chama uma de leitura e uma de escrita, e mostra a auditoria')]
class SmokeTestErp extends Command
{
    use InteractsWithTenant;

    public function handle(ErpGateway $gateway): int
    {
        return $this->asTenant(function () use ($gateway): int {
            $firstAuditId = (int) AuditLog::query()->max('id');

            try {
                $tools = $gateway->tools();
                $this->components->twoColumnDetail('Ferramentas expostas pelo ERP', (string) count($tools));

                $read = $gateway->call('crm.search_accounts', ['query' => 'Beira']);
                $this->components->twoColumnDetail('Leitura: crm.search_accounts', $read->ok ? count($read->data['accounts'] ?? []).' cliente(s)' : 'erro: '.$read->text);

                $write = $gateway->call('leads.create', [
                    'title' => 'Lead de teste da E01',
                    'company_name' => 'Empresa de Teste, Lda',
                    'source' => 'other',
                    'estimated_value' => 100000,
                    'idempotency_key' => 'e01-smoke-'.Str::uuid(),
                ]);
                $this->components->twoColumnDetail('Escrita: leads.create', $write->ok ? (string) ($write->data['lead']['id'] ?? '?') : 'erro: '.$write->text);
            } catch (ErpException $e) {
                $this->components->error($e->getMessage());

                return self::FAILURE;
            }

            $rows = AuditLog::query()->where('id', '>', $firstAuditId)->orderBy('id')->get();

            $this->newLine();
            $this->line('Registos em audit_logs:');
            $this->table(['id', 'acção', 'ferramenta', 'resultado', 'ms'], $rows->map(fn (AuditLog $log) => [
                $log->id,
                $log->action,
                $log->payload['tool'] ?? '—',
                $log->result->value,
                $log->payload['duration_ms'] ?? '—',
            ])->all());

            $audited = $rows->where('action', 'erp.tool_call')->pluck('payload.tool')->all();

            if (! $read->ok || ! $write->ok || ! in_array('crm.search_accounts', $audited, true) || ! in_array('leads.create', $audited, true)) {
                $this->components->error('A prova da E01 falhou.');

                return self::FAILURE;
            }

            $this->components->info('E01 confirmada: leitura e escrita chamadas e auditadas.');

            return self::SUCCESS;
        });
    }
}
