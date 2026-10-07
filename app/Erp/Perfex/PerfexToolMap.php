<?php

namespace App\Erp\Perfex;

/**
 * The contract tools (docs/ERP-MCP-CONTRACT.md) and the Perfex tools that do
 * the same job. Agent templates and instructions speak the contract; on a
 * Perfex ERP an agent that has a contract tool gets these instead, and its
 * prompt carries the correspondence (docs/DECISOES.md, ERP real).
 */
final class PerfexToolMap
{
    /**
     * Contract tool => Perfex tools. An empty list means Perfex has nothing
     * equivalent yet.
     *
     * @var array<string, list<string>>
     */
    public const EQUIVALENTS = [
        'erp.whoami' => ['whoami'],
        'erp.health' => ['whoami'],
        'erp.search' => ['list_customers', 'list_leads', 'list_vendors', 'list_projects', 'list_invoices'],

        'crm.search_accounts' => ['list_customers'],
        'crm.get_account' => ['get_customer', 'list_contacts'],
        'crm.create_contact' => ['create_contact'],
        'crm.update_account' => ['update_customer', 'update_contact'],

        'leads.create' => ['create_lead', 'list_lead_sources', 'list_lead_statuses'],
        'leads.update' => ['update_lead', 'update_lead_status', 'list_lead_statuses'],
        'leads.search' => ['list_leads', 'get_lead'],
        'leads.attach_document' => ['create_note'],

        'projects.create' => ['create_project'],
        'projects.get' => ['get_project', 'list_project_milestones'],
        'projects.list' => ['list_projects'],
        'projects.list_by_account' => ['list_projects'],
        'projects.update_status' => ['update_project_status'],

        'invoices.create_draft' => ['create_invoice', 'list_items', 'list_taxes'],
        'invoices.issue' => ['update_invoice_status'],
        'invoices.list_receivables' => ['list_invoices'],
        'invoices.get' => ['get_invoice'],

        // Quoting to clients: the RFQ flow had no ERP tool until now.
        'quotes.create' => ['create_estimate', 'create_proposal', 'list_items', 'list_taxes'],
        'quotes.client_rfq' => ['list_rfqs', 'get_rfq', 'create_rfq', 'create_purchase_request_from_rfq'],

        'procurement.create_rfq' => ['create_purchase_request', 'list_purchase_items', 'list_purchase_units'],
        'procurement.list_suppliers' => ['list_vendors', 'get_vendor'],
        'procurement.compare_quotes' => ['list_purchase_quotations', 'get_purchase_quotation'],
        'procurement.record_quote' => ['create_purchase_quotation'],
        'procurement.create_po_draft' => ['create_purchase_order'],
        'procurement.list_orders' => ['list_purchase_orders', 'get_purchase_order'],
        'procurement.receive' => ['update_purchase_order_delivery'],

        'expenses.create' => ['create_expense', 'list_expense_categories'],
        'expenses.classify' => ['update_expense', 'list_expense_categories'],
        'expenses.list_by_project' => ['list_expenses'],

        'hr.list_employees' => ['list_staff', 'list_departments'],
        'hr.attendance_summary' => [],
        'hr.list_leave' => [],
        'hr.prepare_payroll_draft' => [],
        'hr.list_openings' => [],
        'hr.create_candidate' => [],
        'hr.list_candidates' => [],
        'hr.create_onboarding' => [],
    ];

    /**
     * Perfex capability keys ("erp.list_customers") that stand in for a
     * contract capability key ("erp.crm.search_accounts").
     *
     * @return list<string>
     */
    public static function capabilitiesFor(string $contractKey): array
    {
        $tool = str_starts_with($contractKey, 'erp.') ? substr($contractKey, 4) : $contractKey;

        return array_map(fn (string $native) => 'erp.'.$native, self::EQUIVALENTS[$tool] ?? []);
    }

    /**
     * Contract tools Perfex cannot do yet.
     *
     * @return list<string>
     */
    public static function missing(): array
    {
        return array_keys(array_filter(self::EQUIVALENTS, fn (array $natives) => $natives === []));
    }
}
