<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpException;
use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;

/**
 * Human resources (E07): people, HR dates, payroll and recruiting. Not in
 * section 8.3: proposed to the ERP team in docs/ERP-MCP-CONTRACT.md. The ERP
 * processes payroll; the platform only reads runs and payslips, and a payroll
 * it prepares is only ever a draft. Attendance is in Modules\Attendance.
 *
 * PDFs (payslips, contracts, letters) are real-ERP-only: this server cannot
 * serve a download link, so asking for one is a readable error.
 */
final class Hr implements Module
{
    use BuildsTools;

    /** Employee social security (INSS) rate. */
    private const INSS_EMPLOYEE = 0.03;

    private const INSS_EMPLOYER = 0.04;

    /** Fictional flat IRPS for the fake runs; the real ERP applies the IRPS table. */
    private const IRPS_ILLUSTRATIVE = 0.10;

    /** Overtime is paid at 150% of the hourly rate (8 h × 22 days). */
    private const OVERTIME_FACTOR = 1.5;

    private const ID_DOCUMENTS = ['bi', 'passport', 'dire'];

    private const UPCOMING_KINDS = ['probation_end', 'contract_end', 'id_expiry', 'document_expiry', 'certificate_expiry', 'birthday', 'anniversary', 'overtime_limit', 'sick_leave', 'disciplinary', 'filing', 'asset_return'];

    public function tools(): array
    {
        return [
            $this->read('hr.list_employees', 'Lista os colaboradores activos (cargo, departamento, chefia, admissão, tipo e fim do contrato), filtrando por departamento. Sem salários nem documentos.',
                fn (JsonSchema $s) => ['department' => $s->string()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['department' => 'nullable|string']);
                    $all = $store->all('employees');

                    return ['employees' => array_values(array_map(fn (array $e) => $this->record($e, $all), array_filter($all, fn (array $e) => $e['status'] === 'active'
                        && $this->matches($e['department'], $args['department'] ?? ''))))];
                }),

            $this->read('hr.get_employee', 'Ficha de RH de um colaborador: registo, contrato (com salário base), documentos e validades, pedidos de férias e as datas que se aproximam (90 dias).',
                fn (JsonSchema $s) => ['employee_id' => $s->string()->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['employee_id' => 'required|string']);
                    $employee = $store->find('employees', $args['employee_id'], 'Colaborador');

                    return [
                        'employee' => [...$this->record($employee, $store->all('employees')), 'birth_date' => $employee['birth_date'] ?? null, 'phone' => $employee['phone'] ?? null,
                            'termination_date' => $employee['termination_date'] ?? null, 'termination_reason' => $employee['termination_reason'] ?? null, 'notes' => $employee['notes'] ?? null],
                        'contract' => [...$employee['contract'] ?? [], 'base_salary' => $employee['base_salary'], 'currency' => 'MZN'],
                        'documents' => $employee['documents'] ?? [],
                        'leave' => array_values(array_filter($store->all('leave'), fn (array $l) => $l['employee_id'] === $employee['id'])),
                        'upcoming' => $this->upcoming($store, 90, null, $employee['id']),
                    ];
                }),

            $this->read('hr.upcoming_dates', 'Datas de RH nos próximos N dias: fim do período experimental e de contratos a prazo, documentos de identificação, certificados e outros documentos a expirar, aniversários, anos de casa, limites de horas extraordinárias, prazos legais. Cada data com os dias que faltam (days_left).',
                fn (JsonSchema $s) => [
                    'days' => $s->integer()->min(1)->max(365)->description('Dias à frente, 1 a 365 (por omissão 60).'),
                    'kind' => $s->string()->enum(self::UPCOMING_KINDS),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['days' => 'nullable|integer|between:1,365', 'kind' => 'nullable|in:'.implode(',', self::UPCOMING_KINDS)]);

                    return ['today' => $this->today()->toDateString(), 'items' => $this->upcoming($store, (int) ($args['days'] ?? 60), $args['kind'] ?? null)];
                }),

            $this->read('hr.people_indicators', 'Indicadores de pessoas: efectivo por departamento, admissões e saídas em 12 meses, rotatividade, absentismo do último mês fechado, horas extraordinárias do ano e custo salarial por colaborador.',
                fn (JsonSchema $s) => [],
                fn (array $args, FakeErpStore $store): array => $this->indicators($store)),

            $this->write('hr.update_employee', 'Actualiza a ficha de RH de um colaborador (cargo, departamento, chefia, estado, cessação, validade do documento de identificação, contactos, notas). Só mudam os campos indicados. Cessar (status terminated) exige termination_date.',
                fn (JsonSchema $s) => [
                    'employee_id' => $s->string()->required(),
                    'position' => $s->string(),
                    'department' => $s->string(),
                    'manager_id' => $s->string(),
                    'status' => $s->string()->enum(['active', 'suspended', 'terminated']),
                    'termination_date' => $s->string()->format('date')->description('Último dia de trabalho.'),
                    'termination_reason' => $s->string(),
                    'id_expiry_date' => $s->string()->format('date')->description('Nova validade do documento de identificação.'),
                    'phone' => $s->string(),
                    'email' => $s->string(),
                    'notes' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $data = $this->validate($args, [
                        'employee_id' => 'required|string', 'position' => 'nullable|string|max:255', 'department' => 'nullable|string|max:255', 'manager_id' => 'nullable|string',
                        'status' => 'nullable|in:active,suspended,terminated', 'termination_date' => 'nullable|date_format:Y-m-d', 'termination_reason' => 'nullable|string|max:500',
                        'id_expiry_date' => 'nullable|date_format:Y-m-d', 'phone' => 'nullable|string|max:50', 'email' => 'nullable|email', 'notes' => 'nullable|string|max:2000',
                    ]);
                    $employee = $store->find('employees', $data['employee_id'], 'Colaborador');

                    if (($data['status'] ?? null) === 'terminated' && ! isset($data['termination_date'])) {
                        throw new FakeErpException('Para cessar o contrato indica termination_date (o último dia de trabalho).');
                    }

                    if (isset($data['manager_id'])) {
                        $store->find('employees', $data['manager_id'], 'Chefia');
                    }

                    $changes = array_intersect_key($data, array_flip(['position', 'department', 'manager_id', 'status', 'termination_date', 'termination_reason', 'phone', 'email', 'notes']));

                    if (isset($data['id_expiry_date'])) {
                        $changes['documents'] = array_map(fn (array $d) => in_array($d['type'], self::ID_DOCUMENTS, true) ? [...$d, 'expiry_date' => $data['id_expiry_date']] : $d, $employee['documents'] ?? []);
                    }

                    if ($changes === []) {
                        throw new FakeErpException('Não indicaste nenhum campo para mudar.');
                    }

                    $updated = $store->update('employees', $employee['id'], $changes);

                    return ['employee' => $this->record($updated, $store->all('employees')), 'changed' => array_keys(array_diff_key($data, ['employee_id' => true]))];
                }),

            $this->write('hr.prepare_payroll_draft', 'Prepara o rascunho da folha de salários de um mês a partir da assiduidade. Não processa nem paga: um humano aprova no ERP.',
                fn (JsonSchema $s) => ['period' => $s->string()->description('Mês, AAAA-MM.')->required()],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['period' => 'required|date_format:Y-m']);
                    $lines = $this->lines($store, $args['period']);

                    return ['payroll' => $store->insert('payrolls', 'PAY', [
                        'period' => $args['period'], 'status' => 'draft', 'currency' => 'MZN',
                        'lines' => array_map(fn (array $l) => array_intersect_key($l, array_flip(['employee_id', 'employee', 'base_salary', 'overtime_pay', 'absence_deduction', 'gross', 'inss_employee', 'inss_employer', 'warnings'])), $lines),
                        'total_gross' => round(array_sum(array_column($lines, 'gross')), 2),
                        'note' => 'IRPS calculado pelo ERP no processamento final.',
                    ])];
                }),

            $this->read('hr.list_payroll_runs', 'Folhas de salários processadas no ERP (rascunho, aprovada, paga) com o número de colaboradores e os totais: bruto, INSS, IRPS, líquido e custo para a empresa. Com period, essa folha com uma linha por colaborador e se a assiduidade do mês está fechada.',
                fn (JsonSchema $s) => [
                    'period' => $s->string()->description('Mês, AAAA-MM: devolve essa folha com as linhas.'),
                    'year' => $s->integer(),
                    'status' => $s->string()->enum(['draft', 'approved', 'paid']),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['period' => 'nullable|date_format:Y-m', 'year' => 'nullable|integer|between:2000,2100', 'status' => 'nullable|in:draft,approved,paid']);

                    if (isset($args['period'])) {
                        $run = collect($store->all('payroll_runs'))->firstWhere('period', $args['period'])
                            ?? throw new FakeErpException("Não há folha de salários de {$args['period']} no ERP.");
                        $lines = $this->lines($store, $run['period'], $run['other_earnings']);

                        return ['run' => [...$this->run($run, $lines), 'attendance_closed' => in_array($run['period'], array_column($store->all('attendance_closed'), 'period'), true), 'lines' => $lines]];
                    }

                    $runs = array_filter($store->all('payroll_runs'), fn (array $r) => (! isset($args['year']) || str_starts_with($r['period'], (string) $args['year']))
                        && (! isset($args['status']) || $r['status'] === $args['status']));

                    return ['runs' => array_values(array_map(fn (array $r) => $this->run($r, $this->lines($store, $r['period'], $r['other_earnings'])), $runs))];
                }),

            $this->read('hr.get_payslip', 'O recibo de vencimento de um colaborador num mês: rendimentos, descontos, líquido e INSS da empresa. pdf: true pede o PDF do ERP (download_url e file_name).',
                fn (JsonSchema $s) => [
                    'period' => $s->string()->description('Mês, AAAA-MM.')->required(),
                    'employee_id' => $s->string()->required(),
                    'pdf' => $s->boolean()->description('true: o PDF do recibo, como o ERP o imprime.'),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['period' => 'required|date_format:Y-m', 'employee_id' => 'required|string', 'pdf' => 'nullable|boolean']);

                    if ($args['pdf'] ?? false) {
                        throw new FakeErpException('O ERP de testes não gera PDFs. No ERP real, o recibo vem em PDF (download_url e file_name).');
                    }

                    $run = collect($store->all('payroll_runs'))->firstWhere('period', $args['period'])
                        ?? throw new FakeErpException("Não há folha de salários de {$args['period']} no ERP.");
                    $line = collect($this->lines($store, $run['period'], $run['other_earnings']))->firstWhere('employee_id', $args['employee_id'])
                        ?? throw new FakeErpException("{$args['employee_id']} não tem recibo na folha de {$args['period']}.");

                    return ['payslip' => [
                        'run_id' => $run['id'], 'period' => $run['period'], 'status' => $run['status'], 'currency' => 'MZN',
                        'employee_id' => $line['employee_id'], 'employee' => $line['employee'],
                        'earnings' => array_values(array_filter([
                            ['description' => 'Salário base', 'amount' => $line['base_salary']],
                            $line['overtime_pay'] > 0 ? ['description' => 'Horas extraordinárias', 'amount' => $line['overtime_pay']] : null,
                            $line['other_earnings'] > 0 ? ['description' => 'Outros rendimentos', 'amount' => $line['other_earnings']] : null,
                        ])),
                        'deductions' => array_values(array_filter([
                            $line['absence_deduction'] > 0 ? ['description' => 'Faltas injustificadas', 'amount' => $line['absence_deduction']] : null,
                            ['description' => 'INSS (trabalhador)', 'amount' => $line['inss_employee']],
                            ['description' => 'IRPS', 'amount' => $line['irps']],
                        ])),
                        'gross' => $line['gross'], 'net' => $line['net'], 'inss_employer' => $line['inss_employer'],
                    ]];
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

    /**
     * What anyone may see of a person: no salary, documents or private contacts.
     *
     * @param  array<string, mixed>  $employee
     * @param  list<array<string, mixed>>  $all
     * @return array<string, mixed>
     */
    private function record(array $employee, array $all): array
    {
        $names = array_column($all, 'name', 'id');

        return [
            'id' => $employee['id'], 'name' => $employee['name'], 'position' => $employee['position'], 'department' => $employee['department'],
            'manager_id' => $employee['manager_id'] ?? null, 'manager' => $names[$employee['manager_id'] ?? ''] ?? null,
            'hire_date' => $employee['hire_date'], 'status' => $employee['status'], 'email' => $employee['email'],
            'contract_type' => $employee['contract']['type'] ?? null, 'contract_end' => $employee['contract']['end_date'] ?? null,
        ];
    }

    /**
     * One line per active employee for a month, from its attendance: base pay,
     * overtime, unjustified absences and other earnings.
     *
     * @param  array<string, float>  $otherEarnings
     * @return list<array<string, mixed>>
     */
    private function lines(FakeErpStore $store, string $period, array $otherEarnings = []): array
    {
        $attendance = array_column(array_filter($store->all('attendance'), fn (array $a) => $a['period'] === $period), null, 'employee_id');

        if ($attendance === []) {
            throw new FakeErpException("Sem assiduidade fechada para {$period}: não é possível preparar a folha.");
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
            $other = (float) ($otherEarnings[$employee['id']] ?? 0);
            $gross = round($employee['base_salary'] + $overtime + $other - $deduction, 2);
            $inss = round($gross * self::INSS_EMPLOYEE, 2);
            $irps = round(($gross - $inss) * self::IRPS_ILLUSTRATIVE, 2);

            $lines[] = [
                'employee_id' => $employee['id'], 'employee' => $employee['name'], 'base_salary' => $employee['base_salary'],
                'overtime_hours' => (float) ($a['overtime_hours'] ?? 0), 'overtime_pay' => $overtime,
                'absences_unjustified' => (int) ($a['absences_unjustified'] ?? 0), 'absence_deduction' => $deduction,
                'other_earnings' => $other, 'gross' => $gross, 'inss_employee' => $inss, 'irps' => $irps,
                'net' => round($gross - $inss - $irps, 2), 'inss_employer' => round($gross * self::INSS_EMPLOYER, 2),
                'warnings' => $a === null ? ['sem registo de assiduidade'] : [],
            ];
        }

        return $lines;
    }

    /**
     * A run with its totals.
     *
     * @param  array<string, mixed>  $run
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function run(array $run, array $lines): array
    {
        $sum = fn (string $key) => round(array_sum(array_column($lines, $key)), 2);

        return [
            'id' => $run['id'], 'period' => $run['period'], 'status' => $run['status'], 'approved_at' => $run['approved_at'], 'paid_at' => $run['paid_at'], 'currency' => 'MZN',
            'totals' => ['employees' => count($lines), 'gross' => $sum('gross'), 'inss_employee' => $sum('inss_employee'), 'inss_employer' => $sum('inss_employer'),
                'irps' => $sum('irps'), 'net' => $sum('net'), 'employer_cost' => round($sum('gross') + $sum('inss_employer'), 2)],
        ];
    }

    /**
     * Dated HR items from today to $days ahead, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(FakeErpStore $store, int $days, ?string $kind = null, ?string $employeeId = null): array
    {
        $today = $this->today();
        $limit = $today->copy()->addDays($days);
        $items = [];

        $add = function (string $itemKind, ?string $date, array $employee, string $text) use (&$items, $today, $limit): void {
            if ($date === null) {
                return;
            }

            $when = Carbon::parse($date, Attendance::TIMEZONE);

            if ($when->lt($today) || $when->gt($limit)) {
                return;
            }

            $items[] = ['kind' => $itemKind, 'date' => $when->toDateString(), 'days_left' => (int) $today->diffInDays($when), 'employee_id' => $employee['id'], 'employee' => $employee['name'], 'text' => $text];
        };

        foreach ($store->all('employees') as $e) {
            if ($e['status'] !== 'active' || ($employeeId !== null && $e['id'] !== $employeeId)) {
                continue;
            }

            $add('contract_end', $e['contract']['end_date'] ?? null, $e, "Fim do contrato a prazo de {$e['name']}");
            $add('probation_end', $e['contract']['probation_end'] ?? null, $e, "Fim do período experimental de {$e['name']}");

            foreach ($e['documents'] ?? [] as $document) {
                [$documentKind, $label] = match (true) {
                    in_array($document['type'], self::ID_DOCUMENTS, true) => ['id_expiry', 'O documento de identificação'],
                    $document['type'] === 'certificate' => ['certificate_expiry', $document['title'] ?? 'Um certificado'],
                    default => ['document_expiry', $document['title'] ?? 'Um documento'],
                };
                $add($documentKind, $document['expiry_date'] ?? null, $e, "{$label} de {$e['name']} expira");
            }

            if (isset($e['birth_date'])) {
                $add('birthday', $this->nextYearly($e['birth_date'], $today), $e, "Aniversário de {$e['name']}");
            }

            $anniversary = $this->nextYearly($e['hire_date'], $today);
            $years = (int) substr($anniversary, 0, 4) - (int) substr($e['hire_date'], 0, 4);

            if ($years >= 1) {
                $add('anniversary', $anniversary, $e, "{$e['name']} faz {$years} ".($years === 1 ? 'ano' : 'anos').' de casa');
            }
        }

        $items = array_values(array_filter($items, fn (array $i) => $kind === null || $i['kind'] === $kind));
        usort($items, fn (array $a, array $b) => [$a['date'], $a['employee']] <=> [$b['date'], $b['employee']]);

        return $items;
    }

    /**
     * The next time (today included) a yearly date comes round.
     */
    private function nextYearly(string $date, Carbon $today): string
    {
        $monthDay = substr($date, 5);

        foreach ([$today->year, $today->year + 1] as $year) {
            $candidate = $monthDay === '02-29' && ! checkdate(2, 29, $year) ? "{$year}-02-28" : "{$year}-{$monthDay}";

            if ($candidate >= $today->toDateString()) {
                return $candidate;
            }
        }

        return ($today->year + 1).'-'.$monthDay;
    }

    /**
     * @return array<string, mixed>
     */
    private function indicators(FakeErpStore $store): array
    {
        $today = $this->today();
        $yearAgo = $today->copy()->subYear()->toDateString();
        $employees = $store->all('employees');
        $active = array_values(array_filter($employees, fn (array $e) => $e['status'] === 'active'));
        $exits = count(array_filter($employees, fn (array $e) => $e['status'] === 'terminated' && ($e['termination_date'] ?? '') >= $yearAgo));

        $closed = array_column($store->all('attendance_closed'), 'period');
        sort($closed);
        $lastMonth = end($closed) ?: null;
        $monthRows = array_filter($store->all('attendance'), fn (array $a) => $a['period'] === $lastMonth);
        $expected = array_sum(array_column($monthRows, 'working_days'));

        $runs = array_values(array_filter($store->all('payroll_runs'), fn (array $r) => in_array($r['status'], ['approved', 'paid'], true)));
        usort($runs, fn (array $a, array $b) => $b['period'] <=> $a['period']);
        $latest = $runs[0] ?? null;
        $cost = $latest !== null ? $this->run($latest, $this->lines($store, $latest['period'], $latest['other_earnings']))['totals'] : null;

        return [
            'as_of' => $today->toDateString(),
            'headcount' => count($active),
            'by_department' => array_count_values(array_column($active, 'department')),
            'hires_12m' => count(array_filter($active, fn (array $e) => $e['hire_date'] >= $yearAgo)),
            'exits_12m' => $exits,
            'turnover_12m_percent' => $active === [] ? 0.0 : round($exits / count($active) * 100, 1),
            'absenteeism' => ['month' => $lastMonth, 'unjustified_days' => array_sum(array_column($monthRows, 'absences_unjustified')),
                'percent' => $expected > 0 ? round(array_sum(array_column($monthRows, 'absences_unjustified')) / $expected * 100, 1) : null],
            'overtime_hours_year' => (float) array_sum(array_column(array_filter($store->all('attendance'), fn (array $a) => str_starts_with($a['period'], (string) $today->year)), 'overtime_hours')),
            'payroll_cost_per_employee' => $cost !== null && $cost['employees'] > 0 ? ['period' => $latest['period'], 'amount' => round($cost['employer_cost'] / $cost['employees'], 2), 'currency' => 'MZN'] : null,
        ];
    }

    private function today(): Carbon
    {
        return now()->setTimezone(Attendance::TIMEZONE)->startOfDay();
    }
}
