<?php

/*
|--------------------------------------------------------------------------
| Absolute ceiling (section 12.3)
|--------------------------------------------------------------------------
|
| Whatever an agent's autonomy level, these actions never run without a
| recorded human decision. It is a closed list, checked before the level.
| Capability keys are "erp.<tool>" for ERP tools and the local capability key
| otherwise. Conditional cases (for example an email to a new external
| contact) are decided by the capability itself through ceilingReason().
|
*/

return [

    /*
    | What leaves the company: read for sensitive data (cards, passwords,
    | keys, identity numbers, health) before it goes (docs/DECISOES.md,
    | "Dados sensíveis no que sai"). When found, a person decides.
    */
    'outgoing' => ['comms.send_email', 'briefings.publish', 'notify.user'],

    'ceiling' => [
        // Payments and money leaving the company.
        'erp.payments.create' => 'Saída de dinheiro ou instrução de pagamento',
        'erp.payments.execute' => 'Saída de dinheiro ou instrução de pagamento',

        // Issuing an invoice to a client.
        'erp.invoices.issue' => 'Emissão de factura a cliente',

        // Signing or accepting contracts.
        'erp.contracts.sign' => 'Assinatura ou aceitação de contrato',
        'erp.contracts.accept' => 'Assinatura ou aceitação de contrato',

        // Hiring, dismissal or contract changes for people. An HR record update
        // can set the status to terminated and the last working day.
        'erp.hr.hire' => 'Contratação, cessação ou alteração contratual',
        'erp.hr.terminate' => 'Contratação, cessação ou alteração contratual',
        'erp.hr.update_contract' => 'Contratação, cessação ou alteração contratual',
        'erp.hr.update_employee' => 'Contratação, cessação ou alteração contratual',

        // The same actions in the live Perfex-based ERP (docs/DECISOES.md, ERP real).
        'erp.create_payment' => 'Saída de dinheiro ou instrução de pagamento',
        'erp.update_payment' => 'Saída de dinheiro ou instrução de pagamento',
        'erp.update_invoice_status' => 'Emissão de factura a cliente',
        'erp.create_invoice_from_estimate' => 'Emissão de factura a cliente',
        'erp.create_credit_note' => 'Crédito a cliente',
        'erp.create_credit_note_from_invoice' => 'Crédito a cliente',
        'erp.create_invoice_credit' => 'Crédito a cliente',
        'erp.update_credit_note_status' => 'Crédito a cliente',
        'erp.create_contract' => 'Assinatura ou aceitação de contrato',
        'erp.update_contract' => 'Assinatura ou aceitação de contrato',
        'erp.update_purchase_request_approval' => 'Aprovação no ERP, reservada a pessoas',
        'erp.update_purchase_quotation_approval' => 'Aprovação no ERP, reservada a pessoas',
        'erp.update_purchase_order_approval' => 'Aprovação no ERP, reservada a pessoas',
        'erp.update_purchase_order_status' => 'Confirmação de encomenda a fornecedor',

        // Rethink HR, Assiduidade and Payroll in the live ERP (docs/DECISOES.md, Director de Recursos Humanos).
        'erp.hr_update_employee' => 'Contratação, cessação ou alteração contratual',
        'erp.att_decide_leave_request' => 'Aprovação no ERP, reservada a pessoas',
        'erp.hr_decide_expense_claim' => 'Aprovação no ERP, reservada a pessoas',
        'erp.hr_mark_expense_claim_paid' => 'Saída de dinheiro ou instrução de pagamento',

        // Changing agent or user permissions.
        'platform.permissions.update' => 'Alteração de permissões de agentes ou utilizadores',
    ],

    /*
    | Pay, contracts, identity documents and disciplinary data (docs/DECISOES.md,
    | "Director de Recursos Humanos"). The ERP token acts as an administrator,
    | so the ERP's own per-person scoping protects nothing here: these
    | capabilities run only for a person who asked and has the «Gerir pessoas»
    | permission (people.manage), or in a routine of the agent for such a
    | person; their results show in run traces only to such people. A closed
    | list of capability keys; a trailing * covers a family, and
    | people_data_open names the few of a family that stay open.
    */
    'people_data' => [
        // The contract (docs/ERP-MCP-CONTRACT.md, section 4.2).
        'erp.hr.get_employee',
        'erp.hr.people_indicators',
        'erp.hr.upcoming_dates',
        'erp.hr.list_payroll_runs',
        'erp.hr.get_payslip',
        'erp.hr.prepare_payroll_draft',
        'erp.hr.update_employee',

        // The live Perfex-based ERP: every payroll and every Rethink HR tool.
        'erp.pay_*',
        'erp.hr_*',
    ],

    // Rethink HR tools with no pay, contract, document or disciplinary data.
    // Assiduidade (erp.att_*), list_staff and list_departments are not on the list at all.
    'people_data_open' => [
        'erp.hr_org_chart',
        'erp.hr_reference_data',
        'erp.hr_leave_balances',
    ],

];
