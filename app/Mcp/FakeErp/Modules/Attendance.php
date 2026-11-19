<?php

namespace App\Mcp\FakeErp\Modules;

use App\Mcp\FakeErp\FakeErpException;
use App\Mcp\FakeErp\FakeErpFixtures;
use App\Mcp\FakeErp\FakeErpStore;
use App\Mcp\FakeErp\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;

/**
 * Attendance (E07): who is in today, day by day, the month, and leave. The
 * days are worked out from each person's rhythm (FakeErpFixtures) and the
 * approved leave, so any date has an answer. The real ERP reads its
 * Timesheets module (docs/ERP-MCP-CONTRACT.md, section 4.2).
 */
final class Attendance implements Module
{
    use BuildsTools;

    /** The ERP keeps Mozambique's time. */
    public const TIMEZONE = 'Africa/Maputo';

    /** Everyone works the same shift in the fake ERP. */
    private const SHIFT = ['name' => 'Normal', 'start' => '08:00', 'end' => '17:00'];

    /** A check-in later than the shift start plus this counts as late. */
    private const LATE_ALLOWED_MINUTES = 10;

    /** The overtime limits the ERP reports (Lei 13/2023, art. 99), as the real ERP applies them. */
    private const OVERTIME_LIMITS = ['week' => 8, 'quarter' => 96, 'year' => 200];

    private const MAX_DAYS = 62;

    public function tools(): array
    {
        return [
            $this->read('hr.attendance_today', 'Assiduidade de um dia (por omissão hoje): turno, entrada, saída, licença aprovada e o estado de cada pessoa (present, late, absent, on_leave, off, no_check_in), com as contagens.',
                fn (JsonSchema $s) => [
                    'date' => $s->string()->format('date')->description('Dia, AAAA-MM-DD. Por omissão, hoje.'),
                    'department' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['date' => 'nullable|date_format:Y-m-d', 'department' => 'nullable|string']);
                    $day = isset($args['date']) ? Carbon::parse($args['date'], self::TIMEZONE) : $this->now()->startOfDay();
                    $people = array_map(fn (array $e) => $this->day($e, $day, $store), $this->people($store, $args['department'] ?? null));

                    return [
                        'date' => $day->toDateString(),
                        'working_day' => $day->isWeekday(),
                        'counts' => array_count_values(array_column($people, 'status')),
                        'people' => $people,
                    ];
                }),

            $this->read('hr.attendance_daily', 'Assiduidade dia a dia por pessoa num período (até 62 dias): estado, entrada, saída, minutos de atraso e licença.',
                fn (JsonSchema $s) => [
                    'from' => $s->string()->format('date')->description('Primeiro dia, AAAA-MM-DD.')->required(),
                    'to' => $s->string()->format('date')->description('Último dia. Por omissão, o primeiro.'),
                    'employee_id' => $s->string(),
                    'department' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['from' => 'required|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'employee_id' => 'nullable|string', 'department' => 'nullable|string']);
                    $from = Carbon::parse($args['from'], self::TIMEZONE);
                    $to = Carbon::parse($args['to'] ?? $args['from'], self::TIMEZONE);

                    if ($from->diffInDays($to) >= self::MAX_DAYS) {
                        throw new FakeErpException('Pede no máximo '.self::MAX_DAYS.' dias de cada vez.');
                    }

                    $people = $this->people($store, $args['department'] ?? null);

                    if (isset($args['employee_id'])) {
                        $people = [$store->find('employees', $args['employee_id'], 'Colaborador')];
                    }

                    $days = [];

                    for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                        foreach ($people as $employee) {
                            $days[] = ['date' => $day->toDateString(), ...$this->day($employee, $day, $store)];
                        }
                    }

                    return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days];
                }),

            $this->read('hr.attendance_summary', 'Assiduidade de um mês por colaborador: presenças, faltas justificadas e injustificadas, atrasos e horas extraordinárias, com as horas do trimestre e do ano face aos limites legais que o ERP aplica; diz se o mês está fechado para salários e quantos pedidos estão por decidir.',
                fn (JsonSchema $s) => [
                    'period' => $s->string()->description('Mês, AAAA-MM.')->required(),
                    'department' => $s->string(),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['period' => 'required|date_format:Y-m', 'department' => 'nullable|string']);
                    $employees = array_column($store->all('employees'), null, 'id');
                    $rows = array_values(array_filter($store->all('attendance'), fn (array $a) => $a['period'] === $args['period']
                        && $this->matches($employees[$a['employee_id']]['department'] ?? null, $args['department'] ?? '')));

                    if ($rows === []) {
                        throw new FakeErpException("Não há registos de assiduidade para {$args['period']}.");
                    }

                    $month = Carbon::parse($args['period'].'-01', self::TIMEZONE);
                    $rows = array_map(function (array $a) use ($employees, $store, $month): array {
                        $quarter = $this->overtimeSince($store, $a['employee_id'], $month->copy()->firstOfQuarter(), $month);
                        $year = $this->overtimeSince($store, $a['employee_id'], $month->copy()->startOfYear(), $month);

                        return [...$a, 'employee' => $employees[$a['employee_id']]['name'] ?? $a['employee_id'],
                            'overtime_quarter_hours' => $quarter, 'overtime_year_hours' => $year,
                            'over_limit' => array_keys(array_filter(['quarter' => $quarter > self::OVERTIME_LIMITS['quarter'], 'year' => $year > self::OVERTIME_LIMITS['year']]))];
                    }, $rows);

                    return [
                        'period' => $args['period'],
                        'month_closed' => in_array($args['period'], array_column($store->all('attendance_closed'), 'period'), true),
                        'open_leave_requests' => count(array_filter($store->all('leave'), fn (array $l) => $l['status'] === 'pending'
                            && $l['start_date'] <= $month->copy()->endOfMonth()->toDateString() && $l['end_date'] >= $month->toDateString())),
                        'overtime_limits' => self::OVERTIME_LIMITS,
                        'rows' => $rows,
                        'totals' => [
                            'absences_unjustified' => array_sum(array_column($rows, 'absences_unjustified')),
                            'overtime_hours' => array_sum(array_column($rows, 'overtime_hours')),
                        ],
                    ];
                }),

            $this->read('hr.list_leave', 'Pedidos de férias e ausências, filtrando por estado, pessoa e período (os que se sobrepõem a from–to).',
                fn (JsonSchema $s) => [
                    'status' => $s->string()->enum(['pending', 'approved', 'rejected']),
                    'employee_id' => $s->string(),
                    'from' => $s->string()->format('date'),
                    'to' => $s->string()->format('date'),
                ],
                function (array $args, FakeErpStore $store): array {
                    $args = $this->validate($args, ['status' => 'nullable|in:pending,approved,rejected', 'employee_id' => 'nullable|string', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
                    $names = array_column($store->all('employees'), 'name', 'id');

                    $leave = array_filter($store->all('leave'), fn (array $l) => (! isset($args['status']) || $l['status'] === $args['status'])
                        && (! isset($args['employee_id']) || $l['employee_id'] === $args['employee_id'])
                        && (! isset($args['from']) || $l['end_date'] >= $args['from'])
                        && (! isset($args['to']) || $l['start_date'] <= $args['to']));

                    return ['leave' => array_values(array_map(fn (array $l) => [...$l, 'employee' => $names[$l['employee_id']] ?? null], $leave))];
                }),
        ];
    }

    /**
     * One person's day as the ERP would record it.
     *
     * @param  array<string, mixed>  $employee
     * @return array<string, mixed>
     */
    private function day(array $employee, Carbon $day, FakeErpStore $store): array
    {
        $now = $this->now();
        $rhythm = FakeErpFixtures::attendanceRhythm()[$employee['id']] ?? ['check_in' => '08:00', 'check_out' => '17:00'];
        $date = $day->toDateString();
        $leave = collect($store->all('leave'))->first(fn (array $l) => $l['employee_id'] === $employee['id'] && $l['status'] === 'approved'
            && $l['start_date'] <= $date && $l['end_date'] >= $date);
        $base = ['employee_id' => $employee['id'], 'employee' => $employee['name'], 'department' => $employee['department']];

        if (! $day->isWeekday()) {
            return [...$base, 'status' => 'off', 'shift' => null, 'check_in' => null, 'check_out' => null, 'late_minutes' => 0, 'leave' => null];
        }

        $shift = self::SHIFT;

        if ($leave !== null) {
            return [...$base, 'status' => 'on_leave', 'shift' => $shift, 'check_in' => null, 'check_out' => null, 'late_minutes' => 0, 'leave' => $leave['type']];
        }

        $at = fn (string $time) => Carbon::parse("{$date} {$time}", self::TIMEZONE);
        $late = in_array($day->dayOfWeekIso, $rhythm['late_weekdays'] ?? [], true);
        $checkIn = in_array($day->dayOfWeekIso, $rhythm['absent_weekdays'] ?? [], true) ? null : ($late ? ($rhythm['late_check_in'] ?? $rhythm['check_in']) : $rhythm['check_in']);

        // Today, nothing after the current time has happened yet.
        if ($checkIn !== null && $at($checkIn)->gt($now)) {
            $checkIn = null;
        }

        if ($checkIn === null) {
            $started = $at($shift['start'])->lte($now);

            return [...$base, 'status' => $started ? 'absent' : 'no_check_in', 'shift' => $shift, 'check_in' => null, 'check_out' => null, 'late_minutes' => 0, 'leave' => null];
        }

        $minutesLate = max(0, (int) $at($shift['start'])->diffInMinutes($at($checkIn), false));
        $checkOut = $at($rhythm['check_out'])->lte($now) ? $rhythm['check_out'] : null;

        return [...$base, 'status' => $minutesLate > self::LATE_ALLOWED_MINUTES ? 'late' : 'present', 'shift' => $shift,
            'check_in' => $checkIn, 'check_out' => $checkOut, 'late_minutes' => $minutesLate > self::LATE_ALLOWED_MINUTES ? $minutesLate : 0, 'leave' => null];
    }

    /**
     * Active people, optionally of one department.
     *
     * @return list<array<string, mixed>>
     */
    private function people(FakeErpStore $store, ?string $department): array
    {
        return array_values(array_filter($store->all('employees'), fn (array $e) => $e['status'] === 'active' && $this->matches($e['department'], $department ?? '')));
    }

    /**
     * Overtime hours recorded from the first month to the last, inclusive.
     */
    private function overtimeSince(FakeErpStore $store, string $employeeId, Carbon $first, Carbon $last): float
    {
        return (float) array_sum(array_column(array_filter($store->all('attendance'), fn (array $a) => $a['employee_id'] === $employeeId
            && $a['period'] >= $first->format('Y-m') && $a['period'] <= $last->format('Y-m')), 'overtime_hours'));
    }

    private function now(): Carbon
    {
        return now()->setTimezone(self::TIMEZONE);
    }
}
