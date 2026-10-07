<?php

namespace App\Erp\Perfex;

use App\Erp\ErpCallResult;
use Closure;
use Illuminate\Support\Carbon;

/**
 * Answers the contract reads the platform's own code depends on (client
 * sheet, margins, month summary, bank reconciliation) from a Perfex ERP. Each
 * answer is built from Perfex reads made through the gateway, so every one of
 * them is audited; nothing here writes.
 *
 * Agents do not go through here: they get the Perfex tools themselves
 * (PerfexToolMap).
 */
final class PerfexAdapter
{
    /** Perfex invoice statuses: 1 unpaid, 2 paid, 3 partially paid, 4 overdue, 5 cancelled, 6 draft. */
    private const OPEN_INVOICES = [1, 3, 4];

    private const OVERDUE = 4;

    /** Perfex project statuses and the contract's names for them. */
    private const PROJECT_STATUSES = [1 => 'planned', 2 => 'in_progress', 3 => 'on_hold', 4 => 'cancelled', 5 => 'completed'];

    /** Projects analysed per call: each costs three reads. */
    private const MAX_PROJECTS = 20;

    private const PAGE = 100;

    private const MAX_PAGES = 5;

    /**
     * @param  Closure(string, array<string, mixed>): ErpCallResult  $native  a Perfex call through the gateway
     */
    public function __construct(private readonly Closure $native) {}

    public static function supports(string $tool): bool
    {
        return in_array($tool, ['crm.get_account', 'projects.list_by_account', 'projects.list', 'invoices.list_receivables', 'leads.search'], true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function call(string $tool, array $arguments): ErpCallResult
    {
        $started = hrtime(true);

        try {
            $data = match ($tool) {
                'crm.get_account' => $this->account($arguments),
                'projects.list_by_account' => ['projects' => $this->projectsOf($arguments)],
                'projects.list' => ['projects' => $this->projectsWithMoney($arguments)],
                'invoices.list_receivables' => $this->receivables($arguments),
                'leads.search' => ['leads' => $this->leads($arguments)],
                default => throw new PerfexReadFailed("A plataforma não sabe ler {$tool} deste ERP."),
            };
        } catch (PerfexReadFailed $e) {
            return new ErpCallResult($tool, false, null, $e->getMessage(), $this->elapsed($started));
        }

        return new ErpCallResult($tool, true, $data, (string) json_encode($data, JSON_UNESCAPED_UNICODE), $this->elapsed($started));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function account(array $arguments): array
    {
        $id = $this->id($arguments['account_id'] ?? null);
        $customer = $this->read('get_customer', ['id' => $id]);
        $contacts = array_map(fn (array $c) => [
            'id' => (string) ($c['id'] ?? ''),
            'name' => trim(($c['firstname'] ?? '').' '.($c['lastname'] ?? '')),
            'email' => $c['email'] ?? null,
            'phone' => $c['phonenumber'] ?? null,
            'role' => $c['title'] ?? null,
            'is_primary' => (bool) ($c['is_primary'] ?? false),
        ], (array) ($customer['contacts'] ?? []));
        $primary = collect($contacts)->firstWhere('is_primary', true) ?? ($contacts[0] ?? null);
        $receivables = $this->receivables(['account_id' => $id]);

        return [
            'account' => [
                'id' => (string) ($customer['userid'] ?? $id),
                'name' => $customer['company'] ?? null,
                'nuit' => $customer['vat'] ?? null,
                'email' => $primary['email'] ?? null,
                'phone' => $customer['phonenumber'] ?? null,
                'city' => $customer['city'] ?? null,
                'address' => $customer['address'] ?? null,
                'website' => $customer['website'] ?? null,
                'status' => ($customer['active'] ?? '1') === '1' ? 'active' : 'inactive',
                'currency' => $customer['default_currency'] ?? null,
            ],
            'contacts' => $contacts,
            'projects' => $this->projectsOf(['account_id' => $id]),
            'balance_due' => $receivables['total_outstanding'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function projectsOf(array $arguments): array
    {
        $filters = ['customer_id' => $this->id($arguments['account_id'] ?? null)];

        if (isset($arguments['status']) && ($status = array_search($arguments['status'], self::PROJECT_STATUSES, true)) !== false) {
            $filters['status'] = $status;
        }

        return array_map(fn (array $p) => $this->project($p), $this->all('list_projects', $filters));
    }

    /**
     * Budget, spending and invoicing per project, as the contract's
     * projects.list returns them. Perfex has no project budget: the project
     * cost (fixed-rate projects) stands in for it.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function projectsWithMoney(array $arguments): array
    {
        $filters = [];

        if (isset($arguments['status']) && ($status = array_search($arguments['status'], self::PROJECT_STATUSES, true)) !== false) {
            $filters['status'] = $status;
        }

        $projects = array_slice($this->all('list_projects', $filters), 0, self::MAX_PROJECTS);

        return array_map(function (array $p): array {
            $id = (int) $p['id'];
            $detail = $this->read('get_project', ['id' => $id]);
            $spent = array_sum(array_map(fn (array $e) => (float) ($e['amount'] ?? 0), $this->all('list_expenses', ['project_id' => $id])));
            $invoiced = array_sum(array_map(
                fn (array $i) => (float) ($i['subtotal'] ?? 0),
                array_filter($this->all('list_invoices', ['project_id' => $id]), fn (array $i) => ! in_array((int) ($i['status'] ?? 0), [5, 6], true)),
            ));

            return [
                ...$this->project($p),
                'budget' => (float) ($detail['project_cost'] ?? 0),
                'spent' => round($spent, 2),
                'invoiced' => round($invoiced, 2),
                'currency' => $p['currency'] ?? null,
            ];
        }, $projects);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{receivables: list<array<string, mixed>>, total_outstanding: float, currency: string|null}
     */
    private function receivables(array $arguments): array
    {
        $filters = isset($arguments['account_id']) ? ['customer_id' => $this->id($arguments['account_id'])] : [];
        $statuses = ($arguments['overdue_only'] ?? false) ? [self::OVERDUE] : self::OPEN_INVOICES;
        $today = Carbon::today();
        $rows = [];

        foreach ($statuses as $status) {
            foreach ($this->all('list_invoices', [...$filters, 'status' => $status]) as $invoice) {
                $outstanding = round((float) ($invoice['total_left_to_pay'] ?? $invoice['total'] ?? 0), 2);

                if ($outstanding <= 0) {
                    continue;
                }

                $due = ! empty($invoice['duedate']) ? Carbon::parse($invoice['duedate']) : null;

                $rows[] = [
                    'id' => (string) $invoice['id'],
                    'number' => $invoice['formatted_number'] ?? $invoice['number'] ?? null,
                    'account_id' => (string) ($invoice['customer_id'] ?? ''),
                    'account_name' => $invoice['customer'] ?? null,
                    'project_id' => ! empty($invoice['project_id']) ? (string) $invoice['project_id'] : null,
                    'issue_date' => $invoice['date'] ?? null,
                    'due_date' => $invoice['duedate'] ?? null,
                    'total' => (float) ($invoice['total'] ?? 0),
                    'outstanding' => $outstanding,
                    'currency' => $invoice['currency'] ?? null,
                    'status' => $invoice['status_label'] ?? null,
                    'days_overdue' => $due !== null && $due->lt($today) ? (int) $due->diffInDays($today) : 0,
                ];
            }
        }

        return [
            'receivables' => $rows,
            'total_outstanding' => round(array_sum(array_column($rows, 'outstanding')), 2),
            'currency' => $rows[0]['currency'] ?? null,
        ];
    }

    /**
     * Perfex leads are not tied to a customer id: for a client, search by its
     * company name.
     *
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function leads(array $arguments): array
    {
        $search = $arguments['query'] ?? null;

        if (isset($arguments['account_id'])) {
            $search = $this->read('get_customer', ['id' => $this->id($arguments['account_id'])])['company'] ?? null;

            if ($search === null || $search === '') {
                return [];
            }
        }

        $leads = $this->page('list_leads', array_filter(['search' => $search, 'limit' => 50], fn ($v) => $v !== null && $v !== ''));

        return array_map(fn (array $l) => [
            'id' => (string) ($l['id'] ?? ''),
            'title' => $l['name'] ?? null,
            'company_name' => $l['company'] ?? null,
            'status' => $l['status_name'] ?? $l['status'] ?? null,
            'estimated_value' => isset($l['lead_value']) ? (float) $l['lead_value'] : null,
            'source' => $l['source_name'] ?? $l['source'] ?? null,
            'email' => $l['email'] ?? null,
        ], $leads);
    }

    /**
     * @param  array<string, mixed>  $p
     * @return array<string, mixed>
     */
    private function project(array $p): array
    {
        return [
            'id' => (string) $p['id'],
            'name' => $p['name'] ?? null,
            'account_id' => (string) ($p['customer_id'] ?? ''),
            'account_name' => $p['customer'] ?? null,
            'status' => self::PROJECT_STATUSES[(int) ($p['status'] ?? 0)] ?? ($p['status_name'] ?? null),
            'start_date' => $p['start_date'] ?? null,
            'end_date' => $p['deadline'] ?? null,
            'progress' => isset($p['progress']) ? (int) $p['progress'] : null,
        ];
    }

    /**
     * Every row of a list tool, a page at a time.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    private function all(string $tool, array $filters): array
    {
        $rows = [];

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $data = $this->read($tool, [...$filters, 'limit' => self::PAGE, 'offset' => $page * self::PAGE]);
            $items = array_values((array) ($data['items'] ?? []));
            $rows = [...$rows, ...$items];

            if (count($items) < self::PAGE || count($rows) >= (int) ($data['total'] ?? 0)) {
                break;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function page(string $tool, array $arguments): array
    {
        return array_values((array) ($this->read($tool, $arguments)['items'] ?? []));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function read(string $tool, array $arguments): array
    {
        $result = ($this->native)($tool, $arguments);

        if (! $result->ok) {
            throw new PerfexReadFailed((string) $result->error());
        }

        return (array) $result->data;
    }

    private function id(mixed $value): int
    {
        if (! is_numeric($value)) {
            throw new PerfexReadFailed('Identificador inválido para o ERP: '.(is_scalar($value) ? (string) $value : 'vazio').'.');
        }

        return (int) $value;
    }

    private function elapsed(int|float $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
