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
        'crm.get_account' => ['get_customer', 'list_contacts', 'download_customer_statement_pdf'],
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
        'invoices.list_receivables' => ['list_invoices', 'list_payments', 'download_invoice_pdf', 'download_customer_statement_pdf', 'export_list_to_excel'],
        'invoices.get' => ['get_invoice', 'download_invoice_pdf', 'download_payment_receipt_pdf'],

        // Quoting to clients: the RFQ flow had no ERP tool until now.
        'quotes.create' => ['create_estimate', 'create_proposal', 'list_items', 'list_taxes', 'list_estimates', 'list_proposals', 'download_estimate_pdf', 'download_proposal_pdf'],
        'quotes.client_rfq' => ['list_rfqs', 'get_rfq', 'create_rfq', 'create_purchase_request_from_rfq'],

        'procurement.create_rfq' => ['create_purchase_request', 'list_purchase_items', 'list_purchase_units', 'list_purchase_requests', 'download_purchase_request_pdf'],
        'procurement.list_suppliers' => ['list_vendors', 'get_vendor'],
        'procurement.compare_quotes' => ['list_purchase_quotations', 'get_purchase_quotation', 'download_purchase_quotation_pdf'],
        'procurement.record_quote' => ['create_purchase_quotation'],
        'procurement.create_po_draft' => ['create_purchase_order'],
        'procurement.list_orders' => ['list_purchase_orders', 'get_purchase_order', 'download_purchase_order_pdf', 'export_list_to_excel'],
        'procurement.receive' => ['update_purchase_order_delivery'],

        'expenses.create' => ['create_expense', 'list_expense_categories'],
        'expenses.classify' => ['update_expense', 'list_expense_categories'],
        'expenses.list_by_project' => ['list_expenses', 'get_expense', 'export_list_to_excel'],

        // Rethink HR, Assiduidade (Timesheets) and Payroll (docs/DECISOES.md, Director de Recursos Humanos).
        'hr.list_employees' => ['list_staff', 'list_departments', 'hr_list_employees', 'hr_org_chart', 'hr_reference_data'],
        'hr.get_employee' => ['hr_get_employee', 'hr_career_history', 'hr_list_documents', 'hr_list_contracts', 'hr_get_contract', 'hr_get_contract_pdf'],
        'hr.people_indicators' => ['hr_people_indicators'],
        'hr.upcoming_dates' => ['hr_upcoming'],
        'hr.update_employee' => ['hr_update_employee'],
        'hr.attendance_today' => ['att_today', 'att_who_is_out', 'att_check_ins'],
        'hr.attendance_daily' => ['att_daily_attendance', 'att_shift_schedule'],
        'hr.attendance_summary' => ['att_attendance_summary', 'att_month_status', 'att_overtime_status'],
        'hr.list_leave' => ['att_list_leave_requests', 'att_get_leave_request', 'att_who_is_out', 'att_leave_types', 'att_leave_balance', 'hr_leave_balances', 'att_holidays'],
        'hr.list_payroll_runs' => ['pay_list_runs', 'pay_get_run', 'pay_annual_summary', 'pay_irps_sheet_pdf'],
        'hr.get_payslip' => ['pay_get_payslip', 'pay_payslip_pdf', 'pay_annual_statement_pdf'],
        // The ERP processes payroll in its own screens on purpose: no tool creates or calculates a run.
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
     * Every Perfex capability key the map names.
     *
     * @return list<string>
     */
    public static function nativeKeys(): array
    {
        return array_values(array_unique(array_map(fn (string $native) => 'erp.'.$native, array_merge(...array_values(self::EQUIVALENTS)))));
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
