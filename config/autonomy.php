<?php

/*
|--------------------------------------------------------------------------
| Absolute ceiling (section 12.3)
|--------------------------------------------------------------------------
|
| Whatever an agent's autonomy level, these actions never run without a
| recorded human decision. It is a closed list, checked before the level.
| Skill keys are "erp.<tool>" for ERP tools and the local skill key
| otherwise. Conditional cases (for example an email to a new external
| contact) are decided by the skill itself through ceilingReason().
|
*/

return [

    'ceiling' => [
        // Payments and money leaving the company.
        'erp.payments.create' => 'Saída de dinheiro ou instrução de pagamento',
        'erp.payments.execute' => 'Saída de dinheiro ou instrução de pagamento',

        // Issuing an invoice to a client.
        'erp.invoices.issue' => 'Emissão de factura a cliente',

        // Signing or accepting contracts.
        'erp.contracts.sign' => 'Assinatura ou aceitação de contrato',
        'erp.contracts.accept' => 'Assinatura ou aceitação de contrato',

        // Hiring, dismissal or contract changes for people.
        'erp.hr.hire' => 'Contratação, cessação ou alteração contratual',
        'erp.hr.terminate' => 'Contratação, cessação ou alteração contratual',
        'erp.hr.update_contract' => 'Contratação, cessação ou alteração contratual',

        // Changing agent or user permissions.
        'platform.permissions.update' => 'Alteração de permissões de agentes ou utilizadores',
    ],

];
