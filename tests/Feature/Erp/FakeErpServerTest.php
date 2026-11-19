<?php

use App\Mcp\Servers\FakeErpServer;
use Illuminate\Support\Carbon;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->store = freshFakeErp();
});

afterEach(fn () => @unlink($this->store));

function fakeErp(string $tool, array $arguments = [])
{
    return FakeErpServer::tool(FakeErpServer::find($tool), $arguments);
}

/**
 * @return array<string, mixed>
 */
function fakeErpData(string $tool, array $arguments = []): array
{
    $data = [];
    fakeErp($tool, $arguments)->assertOk()->assertStructuredContent(function (AssertableJson $json) use (&$data) {
        $data = $json->toArray();
        $json->etc();
    });

    return $data;
}

it('exposes every tool of the section 8.3 contract and the proposed extensions', function () {
    $contract = [
        'crm.search_accounts', 'crm.get_account', 'crm.create_contact', 'crm.update_account',
        'leads.create', 'leads.update', 'leads.search', 'leads.attach_document',
        'projects.create', 'projects.get', 'projects.list_by_account', 'projects.update_status',
        'invoices.create_draft', 'invoices.issue', 'invoices.list_receivables', 'invoices.get',
        'procurement.create_rfq', 'procurement.list_suppliers', 'procurement.compare_quotes', 'procurement.create_po_draft', 'procurement.receive',
        'expenses.create', 'expenses.classify', 'expenses.list_by_project',
        'erp.whoami', 'erp.health', 'erp.search',
    ];

    // Proposed to the ERP team for E05 to E07 (docs/ERP-MCP-CONTRACT.md).
    $extensions = [
        'projects.list', 'procurement.record_quote', 'procurement.list_orders',
        'hr.list_employees', 'hr.attendance_summary', 'hr.list_leave', 'hr.prepare_payroll_draft',
        'hr.list_openings', 'hr.create_candidate', 'hr.list_candidates', 'hr.create_onboarding',
        // The HR Director (docs/DECISOES.md, 19.11.2026).
        'hr.attendance_today', 'hr.attendance_daily', 'hr.upcoming_dates', 'hr.get_employee', 'hr.people_indicators',
        'hr.list_payroll_runs', 'hr.get_payslip', 'hr.update_employee',
    ];

    $names = array_map(fn ($tool) => $tool->name(), FakeErpServer::catalogue());

    expect($names)->toEqualCanonicalizing([...$contract, ...$extensions]);
});

it('requires an idempotency key on every write tool', function () {
    foreach (FakeErpServer::catalogue() as $tool) {
        $schema = $tool->toArray()['inputSchema'];

        expect(in_array('idempotency_key', $schema['required'] ?? [], true))->toBe($tool->writes, $tool->name());
        expect($tool->annotations()['readOnlyHint'])->toBe(! $tool->writes, $tool->name());
    }
});

it('answers reads from the fixtures', function () {
    $this->travelTo('2026-10-04 09:00');

    fakeErp('crm.search_accounts', ['query' => 'beira'])
        ->assertOk()
        ->assertSee('Cimentos do Púnguè, Lda')
        ->assertDontSee('Hotel Baía Azul');

    fakeErp('invoices.list_receivables', ['overdue_only' => true])
        ->assertOk()
        ->assertSee(['INV-0001', 'INV-0002'])
        ->assertDontSee(['INV-0003', 'INV-0004']);
});

it('returns readable errors instead of traces', function () {
    fakeErp('crm.get_account', ['account_id' => 'ACC-9999'])->assertHasErrors(['Cliente ACC-9999 não existe.']);
    fakeErp('crm.create_contact', ['account_id' => 'ACC-0001', 'name' => 'Sem chave'])->assertHasErrors(['idempotency_key']);
});

it('replays a write with the same idempotency key instead of writing twice', function () {
    $arguments = ['title' => 'Obra nova', 'company_name' => 'Teste, Lda', 'source' => 'email', 'idempotency_key' => 'k-1'];

    fakeErp('leads.create', $arguments)->assertOk()->assertSee('LEAD-0004')->assertSee('"idempotent_replay":false');
    fakeErp('leads.create', $arguments)->assertOk()->assertSee('LEAD-0004')->assertSee('"idempotent_replay":true');
    fakeErp('leads.search', ['query' => 'Obra nova'])->assertOk()->assertDontSee('LEAD-0005');

    fakeErp('leads.create', [...$arguments, 'title' => 'Outra obra'])->assertHasErrors(['A chave k-1 já foi usada em leads.create com outros argumentos.']);
});

it('only drafts invoices; issuing needs an approval and a human in the ERP', function () {
    fakeErp('invoices.create_draft', [
        'account_id' => 'ACC-0003',
        'project_id' => 'PRJ-0002',
        'lines' => [['description' => 'Bomba submersível', 'quantity' => 2, 'unit_price' => 45000]],
        'idempotency_key' => 'inv-1',
    ])->assertOk()->assertSee('"status":"draft"')->assertSee('"total":104400');

    fakeErp('invoices.issue', ['invoice_id' => 'INV-0006', 'idempotency_key' => 'issue-1'])->assertHasErrors(['approval reference']);

    fakeErp('invoices.issue', ['invoice_id' => 'INV-0006', 'approval_reference' => 'APR-1', 'idempotency_key' => 'issue-2'])
        ->assertOk()
        ->assertSee('"status":"pending_confirmation"');

    fakeErp('invoices.issue', ['invoice_id' => 'INV-0001', 'approval_reference' => 'APR-2', 'idempotency_key' => 'issue-3'])
        ->assertHasErrors(['não é um rascunho']);
});

it('enforces project status transitions', function () {
    fakeErp('projects.update_status', ['project_id' => 'PRJ-0003', 'status' => 'in_progress', 'idempotency_key' => 'p-1'])
        ->assertHasErrors(['não pode passar de completed para in_progress']);

    fakeErp('projects.update_status', ['project_id' => 'PRJ-0004', 'status' => 'in_progress', 'idempotency_key' => 'p-2'])->assertOk();
});

it('compares quotes from cheapest to most expensive', function () {
    fakeErp('procurement.compare_quotes', ['rfq_id' => 'RFQ-0001'])
        ->assertOk()
        ->assertSee('"cheapest_quote_id":"QUO-0002"')
        ->assertSee('"fastest_quote_id":"QUO-0001"');
});

it('prepares a payroll draft from attendance and never pays', function () {
    fakeErp('hr.prepare_payroll_draft', ['period' => '2026-09', 'idempotency_key' => 'pay-1'])
        ->assertOk()
        ->assertSee('PAY-0001')
        ->assertSee('EMP-0004');

    fakeErp('hr.prepare_payroll_draft', ['period' => '2025-01', 'idempotency_key' => 'pay-2'])
        ->assertHasErrors(['Sem assiduidade fechada']);
});

it('records a supplier quote and lists orders with their receipts', function () {
    fakeErp('procurement.record_quote', ['rfq_id' => 'RFQ-0001', 'supplier_id' => 'SUP-0002', 'total' => 990000, 'delivery_days' => 7, 'idempotency_key' => 'q-1'])
        ->assertOk()
        ->assertSee('QUO-0003');

    fakeErp('procurement.list_orders', ['status' => 'confirmed'])->assertOk()->assertSee('PO-0001')->assertSee('expected_date');
});

it('says who is in on a day: late, absent without leave, on leave, not checked in yet', function () {
    // Thursday 19 November 2026, 09:00 in Maputo: Abel misses Thursdays, Nádia is on approved leave.
    $this->travelTo(Carbon::parse('2026-11-19 09:00', 'Africa/Maputo'));

    $people = collect(fakeErpData('hr.attendance_today')['people'])->keyBy('employee');

    expect($people['Abel Cossa']['status'])->toBe('absent')
        ->and($people['Nádia Tembe'])->status->toBe('on_leave')->leave->toBe('férias')
        ->and($people['Paula Chissano'])->status->toBe('present')->check_in->toBe('07:52')->check_out->toBeNull();

    // Wednesday: Ercília is late; Saturday nobody works; before the shift starts nobody is absent yet.
    fakeErp('hr.attendance_daily', ['from' => '2026-11-18', 'to' => '2026-11-21', 'employee_id' => 'EMP-0003'])
        ->assertOk()->assertSee('"status":"late"')->assertSee('"late_minutes":25')->assertSee('"status":"off"');

    $this->travelTo(Carbon::parse('2026-11-19 07:30', 'Africa/Maputo'));
    fakeErp('hr.attendance_today', ['department' => 'Operações'])->assertOk()->assertSee('"no_check_in":3')->assertDontSee('"absent"');

    fakeErp('hr.attendance_daily', ['from' => '2026-09-01', 'to' => '2026-11-30'])->assertHasErrors(['62 dias']);
});

it('summarises the month against the legal overtime limits and says whether it is closed', function () {
    fakeErp('hr.attendance_summary', ['period' => '2026-10'])
        ->assertOk()
        ->assertSee('"month_closed":true')
        ->assertSee('"overtime_limits":{"week":8,"quarter":96,"year":200}')
        ->assertSee('"overtime_year_hours":82')
        ->assertSee('"employee":"Abel Cossa"');

    fakeErp('hr.list_leave', ['status' => 'pending', 'from' => '2026-11-19', 'to' => '2026-12-03'])
        ->assertOk()->assertSee('LV-0005')->assertSee('Paula Chissano')->assertDontSee('LV-0001');
});

it('lists the HR dates coming up with the days left, and keeps pay out of the employee list', function () {
    $this->travelTo(Carbon::parse('2026-11-19 07:00', 'Africa/Maputo'));

    $items = collect(fakeErpData('hr.upcoming_dates', ['days' => 30])['items']);

    expect($items->map(fn ($i) => [$i['kind'], $i['employee'], $i['days_left']])->all())->toBe([
        ['birthday', 'Paula Chissano', 3],
        ['id_expiry', 'Abel Cossa', 7],
        ['certificate_expiry', 'Tomás Langa', 14],
        ['birthday', 'Nádia Tembe', 23],
        ['contract_end', 'Nádia Tembe', 30],
    ]);

    fakeErp('hr.upcoming_dates', ['days' => 30, 'kind' => 'contract_end'])->assertOk()->assertSee('Nádia Tembe')->assertDontSee('Abel Cossa');
    fakeErp('hr.list_employees')->assertOk()->assertSee('"contract_end":"2026-12-19"')->assertDontSee('base_salary')->assertDontSee('110700456789E');
    fakeErp('hr.get_employee', ['employee_id' => 'EMP-0004'])->assertOk()->assertSee('"base_salary":32000')->assertSee('110700456789E')->assertSee('"manager":"Paula Chissano"');
    fakeErp('hr.people_indicators')->assertOk()->assertSee('"headcount":5')->assertSee('"Operações":3');
});

it('reads payroll runs and payslips, and leaves PDFs to the real ERP', function () {
    fakeErp('hr.list_payroll_runs', ['year' => 2026])->assertOk()->assertSee(['RUN-0001', 'RUN-0002', 'RUN-0003'])->assertDontSee('"lines"');

    fakeErp('hr.list_payroll_runs', ['period' => '2026-10'])
        ->assertOk()
        ->assertSee('"status":"approved"')
        ->assertSee('"attendance_closed":true')
        ->assertSee('"other_earnings":12000')
        ->assertSee('"employees":5');

    fakeErp('hr.list_payroll_runs', ['period' => '2026-11'])->assertHasErrors(['Não há folha de salários de 2026-11']);
    fakeErp('hr.get_payslip', ['period' => '2026-09', 'employee_id' => 'EMP-0004'])->assertOk()->assertSee('Faltas injustificadas')->assertSee('"net"');
    fakeErp('hr.get_payslip', ['period' => '2026-09', 'employee_id' => 'EMP-0004', 'pdf' => true])->assertHasErrors(['não gera PDFs']);
});

it('updates an HR record, and a termination needs its last day', function () {
    fakeErp('hr.update_employee', ['employee_id' => 'EMP-0004', 'status' => 'terminated', 'idempotency_key' => 'u-1'])->assertHasErrors(['termination_date']);

    fakeErp('hr.update_employee', ['employee_id' => 'EMP-0004', 'id_expiry_date' => '2036-11-26', 'phone' => '+258 86 000 0000', 'idempotency_key' => 'u-2'])
        ->assertOk()->assertSee('"changed":["id_expiry_date","phone"]');

    fakeErp('hr.get_employee', ['employee_id' => 'EMP-0004'])->assertOk()->assertSee('2036-11-26')->assertDontSee('2026-11-26');
});
