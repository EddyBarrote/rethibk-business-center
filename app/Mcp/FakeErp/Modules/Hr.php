<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpException;
use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;

/**
 * Human resources (E07). Not in section 8.3: proposed to the ERP team in
 * docs/ERP-MCP-CONTRACT.md. Payroll is only ever a draft.
 */
final class Hr implements Module
{
    use BuildsTools;

    /** Employee social security (INSS) rate. */
    private const INSS_EMPLOYEE = 0.03;

    private const INSS_EMPLOYER = 0.04;

    /** Overtime is paid at 150% of the hourly rate (8 h × 22 days). */
    private const OVERTIME_FACTOR = 1.5;

    public function tools(): array
    {
        return [
            $this->read('hr.list_employees', 'Lista os colaboradores activos, filtrando por departamento.',
                fn (JsonSchema $s) => ['department' => $s->string()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['department' => 'nullable|string']);

                    return ['employees' => array_values(array_filter($store->all('employees'), fn (array $e) => $e['status'] === 'active'
                        && $this->matches($e['department'], $args['department'] ?? '')))];
                }),

            $this->read('hr.attendance_summary', 'Assiduidade de um mês por colaborador: presenças, faltas justificadas e injustificadas, atrasos e horas extraordinárias.',
                fn (JsonSchema $s) => ['period' => $s->string()->description('Mês, AAAA-MM.')->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['period' => 'required|date_format:Y-m']);
                    $employees = array_column($store->all('employees'), null, 'id');
                    $rows = array_values(array_filter($store->all('attendance'), fn (array $a) => $a['period'] === $args['period']));

                    if ($rows === []) {
                        throw new FakeErpException("Não há registos de assiduidade para {$args['period']}.");
                    }

                    return [
                        'period' => $args['period'],
                        'rows' => array_map(fn (array $a) => [...$a, 'employee' => $employees[$a['employee_id']]['name'] ?? $a['employee_id']], $rows),
                        'totals' => [
                            'absences_unjustified' => array_sum(array_column($rows, 'absences_unjustified')),
                            'overtime_hours' => array_sum(array_column($rows, 'overtime_hours')),
                        ],
                    ];
                }),

            $this->read('hr.list_leave', 'Pedidos de férias e ausências, filtrando por estado.',
                fn (JsonSchema $s) => ['status' => $s->string()->enum(['pending', 'approved', 'rejected'])],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['status' => 'nullable|in:pending,approved,rejected']);

                    return ['leave' => array_values(array_filter($store->all('leave'), fn (array $l) => ! isset($args['status']) || $l['status'] === $args['status']))];
                }),

            $this->write('hr.prepare_payroll_draft', 'Prepara o rascunho da folha de salários de um mês a partir da assiduidade. Não processa nem paga: um humano aprova no ERP.',
                fn (JsonSchema $s) => ['period' => $s->string()->description('Mês, AAAA-MM.')->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['period' => 'required|date_format:Y-m']);
                    $attendance = array_column(array_filter($store->all('attendance'), fn (array $a) => $a['period'] === $args['period']), null, 'employee_id');

                    if ($attendance === []) {
                        throw new FakeErpException("Sem assiduidade fechada para {$args['period']}: não é possível preparar a folha.");
                    }

                    $lines = [];

                    foreach ($store->all('employees') as $employee) {
                        if ($employee['status'] !== 'active') {
                            continue;
                        }

                        $a = $attendance[$employee['id']] ?? null;
                        $daily = $employee['base_salary'] / 22;
                        $overtime = round(($a['overtime_hours'] ?? 0) * ($daily / 8) * self::OVERTIME_FACTOR, 2);
                        $deduction = round(($a['absences_unjustified'] ?? 0) * $daily, 2);
                        $gross = round($employee['base_salary'] + $overtime - $deduction, 2);

                        $lines[] = [
                            'employee_id' => $employee['id'], 'employee' => $employee['name'], 'base_salary' => $employee['base_salary'],
                            'overtime_pay' => $overtime, 'absence_deduction' => $deduction, 'gross' => $gross,
                            'inss_employee' => round($gross * self::INSS_EMPLOYEE, 2), 'inss_employer' => round($gross * self::INSS_EMPLOYER, 2),
                            'warnings' => $a === null ? ['sem registo de assiduidade'] : [],
                        ];
                    }

                    return ['payroll' => $store->insert('payrolls', 'PAY', [
                        'period' => $args['period'], 'status' => 'draft', 'currency' => 'MZN', 'lines' => $lines,
                        'total_gross' => round(array_sum(array_column($lines, 'gross')), 2),
                        'note' => 'IRPS calculado pelo ERP no processamento final.',
                    ])];
                }),

            $this->read('hr.list_openings', 'Vagas abertas com os requisitos.',
                fn (JsonSchema $s) => [],
                fn (array $args, FakeErpStore $store): array => ['openings' => array_values(array_filter($store->all('openings'), fn (array $o) => $o['status'] === 'open'))]),

            $this->write('hr.create_candidate', 'Regista uma candidatura a uma vaga, com a avaliação da triagem.',
                fn (JsonSchema $s) => [
                    'opening_id' => $s->string()->required(),
                    'name' => $s->string()->required(),
                    'email' => $s->string(),
                    'score' => $s->number()->min(0)->max(100)->description('Aderência aos requisitos, 0 a 100.'),
                    'summary' => $s->string(),
                    'recommendation' => $s->string()->enum(['shortlist', 'hold', 'reject']),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['opening_id' => 'required|string', 'name' => 'required|string|max:255', 'email' => 'nullable|email', 'score' => 'nullable|numeric|between:0,100', 'summary' => 'nullable|string', 'recommendation' => 'nullable|in:shortlist,hold,reject']);
                    $store->find('openings', $args['opening_id'], 'Vaga');

                    return ['candidate' => $store->insert('candidates', 'CAND', [...$args, 'status' => 'screened'])];
                }),

            $this->read('hr.list_candidates', 'Candidaturas registadas, por vaga.',
                fn (JsonSchema $s) => ['opening_id' => $s->string()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['opening_id' => 'nullable|string']);

                    return ['candidates' => array_values(array_filter($store->all('candidates'), fn (array $c) => ! isset($args['opening_id']) || $c['opening_id'] === $args['opening_id']))];
                }),

            $this->write('hr.create_onboarding', 'Abre o plano de integração de um novo colaborador (rascunho de tarefas).',
                fn (JsonSchema $s) => [
                    'name' => $s->string()->required(),
                    'position' => $s->string()->required(),
                    'start_date' => $s->string()->format('date')->required(),
                    'department' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['name' => 'required|string|max:255', 'position' => 'required|string|max:255', 'start_date' => 'required|date_format:Y-m-d', 'department' => 'nullable|string']);
                    $start = Carbon::parse($args['start_date']);

                    return ['onboarding' => $store->insert('onboardings', 'ONB', [...$args, 'status' => 'draft', 'tasks' => [
                        ['task' => 'Contrato de trabalho assinado', 'due' => $start->copy()->subDays(3)->toDateString()],
                        ['task' => 'Inscrição no INSS e NUIT confirmados', 'due' => $start->copy()->subDay()->toDateString()],
                        ['task' => 'Equipamento e EPI entregues', 'due' => $start->toDateString()],
                        ['task' => 'Apresentação à equipa e regras de segurança', 'due' => $start->toDateString()],
                        ['task' => 'Avaliação do primeiro mês', 'due' => $start->copy()->addMonth()->toDateString()],
                    ]])];
                }),
        ];
    }
}
