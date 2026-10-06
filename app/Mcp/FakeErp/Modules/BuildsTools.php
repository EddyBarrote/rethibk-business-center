<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\FakeErpTool;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

trait BuildsTools
{
    /**
     * @param  Closure(JsonSchema): array<string, Type>  $arguments
     * @param  Closure(array<string, mixed>, FakeErpStore): array<string, mixed>  $handler
     */
    private function read(string $name, string $description, Closure $arguments, Closure $handler): FakeErpTool
    {
        return new FakeErpTool($name, $this->title($name), $description, false, $arguments, $handler);
    }

    /**
     * @param  Closure(JsonSchema): array<string, Type>  $arguments
     * @param  Closure(array<string, mixed>, FakeErpStore): array<string, mixed>  $handler
     */
    private function write(string $name, string $description, Closure $arguments, Closure $handler): FakeErpTool
    {
        return new FakeErpTool($name, $this->title($name), $description, true, $arguments, $handler);
    }

    /**
     * Validation errors become readable isError results.
     *
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $arguments, array $rules): array
    {
        return Validator::make($arguments, $rules)->validate();
    }

    /**
     * Case and accent insensitive "contains".
     */
    private function matches(?string $haystack, string $needle): bool
    {
        return $needle === '' || Str::contains(Str::ascii((string) $haystack), Str::ascii($needle), ignoreCase: true);
    }

    /**
     * The name people read in the console (Capacidades, a agent's capabilities, approvals). The ERP team's server is
     * expected to send its own titles in Portuguese; these are the fake server's.
     */
    private function title(string $name): string
    {
        return self::TITLES[$name] ?? Str::headline(str_replace('.', ' ', $name));
    }

    private const TITLES = [
        'erp.whoami' => 'Identificação no ERP',
        'erp.health' => 'Estado do ERP',
        'erp.search' => 'Pesquisar no ERP',
        'crm.search_accounts' => 'Procurar clientes',
        'crm.get_account' => 'Ficha do cliente no ERP',
        'crm.create_contact' => 'Criar contacto de cliente',
        'crm.update_account' => 'Actualizar ficha de cliente',
        'leads.create' => 'Registar oportunidade',
        'leads.update' => 'Actualizar oportunidade',
        'leads.search' => 'Procurar oportunidades',
        'leads.attach_document' => 'Juntar documento a oportunidade',
        'projects.create' => 'Criar projecto',
        'projects.get' => 'Ficha do projecto',
        'projects.list' => 'Listar projectos',
        'projects.list_by_account' => 'Projectos de um cliente',
        'projects.update_status' => 'Mudar estado do projecto',
        'invoices.create_draft' => 'Preparar rascunho de factura',
        'invoices.issue' => 'Emitir factura',
        'invoices.list_receivables' => 'Facturas por receber',
        'invoices.get' => 'Ver factura',
        'expenses.create' => 'Registar despesa',
        'expenses.classify' => 'Classificar despesa',
        'expenses.list_by_project' => 'Despesas de um projecto',
        'procurement.create_rfq' => 'Pedir cotações',
        'procurement.record_quote' => 'Registar cotação',
        'procurement.list_orders' => 'Listar encomendas',
        'procurement.list_suppliers' => 'Listar fornecedores',
        'procurement.compare_quotes' => 'Comparar cotações',
        'procurement.create_po_draft' => 'Preparar nota de encomenda',
        'procurement.receive' => 'Registar recepção de encomenda',
        'hr.list_employees' => 'Listar colaboradores',
        'hr.attendance_summary' => 'Resumo de assiduidade',
        'hr.list_leave' => 'Pedidos de férias e ausências',
        'hr.prepare_payroll_draft' => 'Preparar folha de salários',
        'hr.list_openings' => 'Vagas abertas',
        'hr.create_candidate' => 'Registar candidatura',
        'hr.list_candidates' => 'Listar candidatos',
        'hr.create_onboarding' => 'Abrir integração de colaborador',
    ];
}
