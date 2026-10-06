<?php

namespace App\Mcp\FakeErp;

/**
 * Fictional data for the fake ERP. Companies, people and NUITs are invented;
 * amounts are in meticais (MZN) with IVA at 16%.
 */
final class FakeErpFixtures
{
    public const IVA_RATE = 0.16;

    /**
     * @return array<string, mixed>
     */
    public static function state(): array
    {
        return [
            'accounts' => self::accounts(),
            'contacts' => self::contacts(),
            'leads' => self::leads(),
            'documents' => [],
            'projects' => self::projects(),
            'invoices' => self::invoices(),
            'suppliers' => self::suppliers(),
            'rfqs' => self::rfqs(),
            'quotes' => self::quotes(),
            'purchase_orders' => self::purchaseOrders(),
            'receipts' => [],
            'expenses' => self::expenses(),
            'employees' => self::employees(),
            'attendance' => self::attendance(),
            'leave' => self::leave(),
            'openings' => self::openings(),
            'candidates' => [],
            'payrolls' => [],
            'onboardings' => [],
            'idempotency' => [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function accounts(): array
    {
        return [
            ['id' => 'ACC-0001', 'name' => 'Cimentos do Púnguè, Lda', 'nuit' => '400118274', 'sector' => 'Construção', 'city' => 'Beira', 'province' => 'Sofala', 'email' => 'geral@cimentospungue.co.mz', 'phone' => '+258 23 312 450', 'payment_terms_days' => 30, 'status' => 'active'],
            ['id' => 'ACC-0002', 'name' => 'Hotel Baía Azul, SA', 'nuit' => '400562091', 'sector' => 'Hotelaria', 'city' => 'Maputo', 'province' => 'Maputo Cidade', 'email' => 'compras@baiaazul.co.mz', 'phone' => '+258 21 488 210', 'payment_terms_days' => 45, 'status' => 'active'],
            ['id' => 'ACC-0003', 'name' => 'Agro Zambeze, Lda', 'nuit' => '401230987', 'sector' => 'Agricultura', 'city' => 'Quelimane', 'province' => 'Zambézia', 'email' => 'administracao@agrozambeze.co.mz', 'phone' => '+258 24 219 337', 'payment_terms_days' => 30, 'status' => 'active'],
            ['id' => 'ACC-0004', 'name' => 'Fundo Comunitário de Nampula', 'nuit' => '500873412', 'sector' => 'Sector público', 'city' => 'Nampula', 'province' => 'Nampula', 'email' => 'aquisicoes@fcnampula.gov.mz', 'phone' => '+258 26 213 004', 'payment_terms_days' => 60, 'status' => 'active'],
            ['id' => 'ACC-0005', 'name' => 'Transportes Limpopo, Lda', 'nuit' => '400991256', 'sector' => 'Logística', 'city' => 'Xai-Xai', 'province' => 'Gaza', 'email' => 'financeiro@translimpopo.co.mz', 'phone' => '+258 28 222 815', 'payment_terms_days' => 30, 'status' => 'active'],
            ['id' => 'ACC-0006', 'name' => 'Clínica Sol Nascente, Lda', 'nuit' => '401447730', 'sector' => 'Saúde', 'city' => 'Matola', 'province' => 'Maputo Província', 'email' => 'direccao@solnascente.co.mz', 'phone' => '+258 21 720 640', 'payment_terms_days' => 30, 'status' => 'inactive'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function contacts(): array
    {
        return [
            ['id' => 'CNT-0001', 'account_id' => 'ACC-0001', 'name' => 'Armando Macuácua', 'role' => 'Director de Operações', 'email' => 'a.macuacua@cimentospungue.co.mz', 'phone' => '+258 84 312 7781'],
            ['id' => 'CNT-0002', 'account_id' => 'ACC-0002', 'name' => 'Celeste Nhantumbo', 'role' => 'Chefe de Compras', 'email' => 'c.nhantumbo@baiaazul.co.mz', 'phone' => '+258 82 455 0193'],
            ['id' => 'CNT-0003', 'account_id' => 'ACC-0003', 'name' => 'Faizal Abdul', 'role' => 'Administrador', 'email' => 'f.abdul@agrozambeze.co.mz', 'phone' => '+258 86 201 4470'],
            ['id' => 'CNT-0004', 'account_id' => 'ACC-0004', 'name' => 'Rosa Muhate', 'role' => 'Responsável de Aquisições (UGEA)', 'email' => 'r.muhate@fcnampula.gov.mz', 'phone' => '+258 84 980 3321'],
            ['id' => 'CNT-0005', 'account_id' => 'ACC-0005', 'name' => 'Jorge Sitoe', 'role' => 'Director Financeiro', 'email' => 'j.sitoe@translimpopo.co.mz', 'phone' => '+258 87 330 1209'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function leads(): array
    {
        return [
            ['id' => 'LEAD-0001', 'title' => 'Reabilitação do armazém de Dondo', 'account_id' => 'ACC-0001', 'company_name' => 'Cimentos do Púnguè, Lda', 'source' => 'email', 'status' => 'qualified', 'estimated_value' => 4_850_000.00, 'currency' => 'MZN', 'notes' => 'Pedido de proposta recebido por email.', 'documents' => []],
            ['id' => 'LEAD-0002', 'title' => 'Concurso: manutenção de estradas terciárias', 'account_id' => 'ACC-0004', 'company_name' => 'Fundo Comunitário de Nampula', 'source' => 'tender', 'status' => 'new', 'estimated_value' => 12_300_000.00, 'currency' => 'MZN', 'notes' => 'Prazo de submissão a confirmar.', 'documents' => []],
            ['id' => 'LEAD-0003', 'title' => 'Frota de manutenção preventiva', 'account_id' => null, 'company_name' => 'Pescas do Índico, Lda', 'source' => 'referral', 'status' => 'contacted', 'estimated_value' => 980_000.00, 'currency' => 'MZN', 'notes' => '', 'documents' => []],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function projects(): array
    {
        return [
            ['id' => 'PRJ-0001', 'account_id' => 'ACC-0002', 'name' => 'Remodelação da ala norte', 'status' => 'in_progress', 'budget' => 7_400_000.00, 'spent' => 3_120_500.00, 'currency' => 'MZN', 'start_date' => '2026-06-01', 'end_date' => '2026-12-15', 'manager' => 'Eng.ª Paula Chissano'],
            ['id' => 'PRJ-0002', 'account_id' => 'ACC-0003', 'name' => 'Sistema de rega da machamba 3', 'status' => 'in_progress', 'budget' => 2_150_000.00, 'spent' => 1_890_000.00, 'currency' => 'MZN', 'start_date' => '2026-07-10', 'end_date' => '2026-10-30', 'manager' => 'Eng. Tomás Langa'],
            ['id' => 'PRJ-0003', 'account_id' => 'ACC-0005', 'name' => 'Oficina de Xai-Xai', 'status' => 'completed', 'budget' => 1_300_000.00, 'spent' => 1_265_400.00, 'currency' => 'MZN', 'start_date' => '2026-02-03', 'end_date' => '2026-05-29', 'manager' => 'Eng. Tomás Langa'],
            ['id' => 'PRJ-0004', 'account_id' => 'ACC-0002', 'name' => 'Painéis solares da lavandaria', 'status' => 'planned', 'budget' => 1_850_000.00, 'spent' => 0.00, 'currency' => 'MZN', 'start_date' => '2026-11-02', 'end_date' => '2027-01-31', 'manager' => 'Eng.ª Paula Chissano'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function invoices(): array
    {
        return [
            self::invoice('INV-0001', 'FT 2026/118', 'ACC-0002', 'PRJ-0001', 'issued', '2026-07-31', '2026-09-14', 2_000_000.00, 1_160_000.00),
            self::invoice('INV-0002', 'FT 2026/131', 'ACC-0003', 'PRJ-0002', 'issued', '2026-08-29', '2026-09-28', 950_000.00, 0.00),
            self::invoice('INV-0003', 'FT 2026/140', 'ACC-0002', 'PRJ-0001', 'issued', '2026-09-15', '2026-10-30', 1_100_000.00, 0.00),
            self::invoice('INV-0004', 'FT 2026/097', 'ACC-0005', 'PRJ-0003', 'issued', '2026-05-30', '2026-06-29', 1_090_000.00, 1_264_400.00),
            self::invoice('INV-0005', null, 'ACC-0003', 'PRJ-0002', 'draft', null, null, 640_000.00, 0.00),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function invoice(string $id, ?string $number, string $account, string $project, string $status, ?string $issued, ?string $due, float $subtotal, float $paid): array
    {
        $iva = round($subtotal * self::IVA_RATE, 2);

        return [
            'id' => $id, 'number' => $number, 'account_id' => $account, 'project_id' => $project, 'status' => $status,
            'issue_date' => $issued, 'due_date' => $due, 'currency' => 'MZN',
            'lines' => [['description' => 'Serviços conforme auto de medição', 'quantity' => 1, 'unit_price' => $subtotal]],
            'subtotal' => $subtotal, 'iva' => $iva, 'total' => $subtotal + $iva, 'amount_paid' => $paid,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function suppliers(): array
    {
        return [
            ['id' => 'SUP-0001', 'name' => 'Ferragens Matola, Lda', 'nuit' => '400337812', 'categories' => ['aço', 'ferragens'], 'city' => 'Matola', 'rating' => 4.2, 'payment_terms_days' => 30],
            ['id' => 'SUP-0002', 'name' => 'Cimentos do Púnguè, Lda', 'nuit' => '400118274', 'categories' => ['cimento', 'agregados'], 'city' => 'Beira', 'rating' => 4.6, 'payment_terms_days' => 15],
            ['id' => 'SUP-0003', 'name' => 'Combustíveis do Save, SA', 'nuit' => '400774120', 'categories' => ['combustível'], 'city' => 'Maputo', 'rating' => 3.9, 'payment_terms_days' => 7],
            ['id' => 'SUP-0004', 'name' => 'InfoTec Moçambique, Lda', 'nuit' => '401002345', 'categories' => ['informática'], 'city' => 'Maputo', 'rating' => 4.0, 'payment_terms_days' => 30],
            ['id' => 'SUP-0005', 'name' => 'Segurança Total EPI, Lda', 'nuit' => '401558902', 'categories' => ['epi'], 'city' => 'Nampula', 'rating' => 4.4, 'payment_terms_days' => 30],
            ['id' => 'SUP-0006', 'name' => 'Aço e Varão do Centro, Lda', 'nuit' => '400669031', 'categories' => ['aço'], 'city' => 'Chimoio', 'rating' => 3.7, 'payment_terms_days' => 45],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rfqs(): array
    {
        return [
            ['id' => 'RFQ-0001', 'title' => 'Varão de aço A500 para a ala norte', 'project_id' => 'PRJ-0001', 'status' => 'quotes_received', 'items' => [['description' => 'Varão A500 Ø12 mm', 'quantity' => 400, 'unit' => 'barra'], ['description' => 'Varão A500 Ø16 mm', 'quantity' => 250, 'unit' => 'barra']], 'supplier_ids' => ['SUP-0001', 'SUP-0006'], 'due_date' => '2026-09-20'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function quotes(): array
    {
        return [
            ['id' => 'QUO-0001', 'rfq_id' => 'RFQ-0001', 'supplier_id' => 'SUP-0001', 'total' => 1_118_000.00, 'currency' => 'MZN', 'delivery_days' => 5, 'valid_until' => '2026-10-20', 'notes' => 'Entrega na obra incluída.'],
            ['id' => 'QUO-0002', 'rfq_id' => 'RFQ-0001', 'supplier_id' => 'SUP-0006', 'total' => 1_042_500.00, 'currency' => 'MZN', 'delivery_days' => 12, 'valid_until' => '2026-10-10', 'notes' => 'Transporte Chimoio–Maputo por conta do cliente.'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function purchaseOrders(): array
    {
        return [
            ['id' => 'PO-0001', 'number' => 'NE 2026/044', 'supplier_id' => 'SUP-0002', 'project_id' => 'PRJ-0002', 'rfq_id' => null, 'quote_id' => null, 'status' => 'confirmed', 'lines' => [['description' => 'Cimento Portland 42,5 (saco 50 kg)', 'quantity' => 600, 'unit_price' => 620.00]], 'total' => 372_000.00, 'currency' => 'MZN', 'expected_date' => '2026-10-02'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function expenses(): array
    {
        return [
            ['id' => 'EXP-0001', 'description' => 'Gasóleo para gerador de obra', 'amount' => 48_600.00, 'currency' => 'MZN', 'date' => '2026-09-12', 'project_id' => 'PRJ-0001', 'supplier' => 'Combustíveis do Save, SA', 'category' => 'combustível', 'status' => 'classified'],
            ['id' => 'EXP-0002', 'description' => 'Ajudas de custo, deslocação a Quelimane', 'amount' => 18_250.00, 'currency' => 'MZN', 'date' => '2026-09-18', 'project_id' => 'PRJ-0002', 'supplier' => null, 'category' => 'deslocações', 'status' => 'classified'],
            ['id' => 'EXP-0003', 'description' => 'Botas e capacetes', 'amount' => 36_900.00, 'currency' => 'MZN', 'date' => '2026-09-25', 'project_id' => null, 'supplier' => 'Segurança Total EPI, Lda', 'category' => null, 'status' => 'draft'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function employees(): array
    {
        return [
            ['id' => 'EMP-0001', 'name' => 'Paula Chissano', 'position' => 'Engenheira de obra', 'department' => 'Operações', 'base_salary' => 85_000.00, 'hire_date' => '2021-03-01', 'status' => 'active', 'email' => 'p.chissano@micomoc.co.mz'],
            ['id' => 'EMP-0002', 'name' => 'Tomás Langa', 'position' => 'Engenheiro de obra', 'department' => 'Operações', 'base_salary' => 82_000.00, 'hire_date' => '2022-01-10', 'status' => 'active', 'email' => 't.langa@micomoc.co.mz'],
            ['id' => 'EMP-0003', 'name' => 'Ercília Mabunda', 'position' => 'Técnica de contabilidade', 'department' => 'Finanças', 'base_salary' => 46_000.00, 'hire_date' => '2023-05-02', 'status' => 'active', 'email' => 'e.mabunda@micomoc.co.mz'],
            ['id' => 'EMP-0004', 'name' => 'Abel Cossa', 'position' => 'Encarregado', 'department' => 'Operações', 'base_salary' => 32_000.00, 'hire_date' => '2020-08-17', 'status' => 'active', 'email' => null],
            ['id' => 'EMP-0005', 'name' => 'Nádia Tembe', 'position' => 'Assistente administrativa', 'department' => 'Administração', 'base_salary' => 28_500.00, 'hire_date' => '2024-02-05', 'status' => 'active', 'email' => 'n.tembe@micomoc.co.mz'],
        ];
    }

    /**
     * Monthly attendance per employee (working days, absences, late
     * arrivals, overtime hours).
     *
     * @return list<array<string, mixed>>
     */
    private static function attendance(): array
    {
        $rows = [];
        $data = [
            'EMP-0001' => [22, 0, 0, 1, 12.0],
            'EMP-0002' => [21, 1, 0, 0, 18.5],
            'EMP-0003' => [22, 0, 0, 3, 0.0],
            'EMP-0004' => [18, 2, 2, 4, 26.0],
            'EMP-0005' => [22, 0, 0, 0, 2.0],
        ];

        foreach (['2026-08', '2026-09'] as $period) {
            foreach ($data as $employee => [$present, $justified, $unjustified, $late, $overtime]) {
                $rows[] = ['employee_id' => $employee, 'period' => $period, 'working_days' => 22, 'days_present' => $present, 'absences_justified' => $justified, 'absences_unjustified' => $unjustified, 'late_arrivals' => $late, 'overtime_hours' => $overtime];
            }
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function leave(): array
    {
        return [
            ['id' => 'LV-0001', 'employee_id' => 'EMP-0002', 'type' => 'férias', 'start_date' => '2026-10-19', 'end_date' => '2026-10-30', 'days' => 10, 'status' => 'pending'],
            ['id' => 'LV-0002', 'employee_id' => 'EMP-0003', 'type' => 'férias', 'start_date' => '2026-12-21', 'end_date' => '2027-01-08', 'days' => 12, 'status' => 'approved'],
            ['id' => 'LV-0003', 'employee_id' => 'EMP-0004', 'type' => 'doença', 'start_date' => '2026-09-08', 'end_date' => '2026-09-09', 'days' => 2, 'status' => 'approved'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function openings(): array
    {
        return [
            ['id' => 'JOB-0001', 'title' => 'Técnico de manutenção electromecânica', 'department' => 'Operações', 'location' => 'Maputo', 'status' => 'open', 'requirements' => ['curso técnico de electromecânica', 'carta de condução', '3 anos de experiência', 'manutenção preventiva', 'português']],
            ['id' => 'JOB-0002', 'title' => 'Contabilista', 'department' => 'Finanças', 'location' => 'Maputo', 'status' => 'open', 'requirements' => ['licenciatura em contabilidade', 'OCAM', 'Primavera ou ERP', 'IVA', 'reconciliação bancária']],
        ];
    }
}
