<?php

namespace App\Http\Controllers\Settings;

use App\Audit\AuditFilters;
use App\Audit\AuditPresenter;
use App\Audit\AuditVocabulary;
use App\Audit\ConfidentialEntries;
use App\Enums\AuditResult;
use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Definições › Auditoria (docs/DECISOES.md, "Auditoria pesquisável e
 * exportável"): the company's whole audit trail, who did what, when and with
 * what result, filtered and exported as CSV. Read only: audit_logs is never
 * changed. Whoever administers the company opens it; the details of entries
 * with pay and personal data only whoever may also manage people.
 */
class AuditController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);

        $filters = AuditFilters::fromRequest($request);
        $confidential = ConfidentialEntries::forCurrentTenant();
        $reads = ConfidentialEntries::readBy($user);

        $page = $filters->apply(AuditLog::query(), $confidential, $reads)
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $page->setCollection(collect((new AuditPresenter($confidential, $reads))->rows($page->getCollection())));

        return Inertia::render('Settings/Audit', [
            'entries' => $page,
            'filters' => [
                'from' => $filters->from,
                'to' => $filters->to,
                'actor' => $filters->actor,
                'action' => $filters->action,
                'result' => $filters->result?->value,
                'subject' => $filters->subject,
                'q' => $filters->q,
            ],
            'options' => fn () => $this->options(),
            'reads_confidential' => $reads,
            'timezone' => (string) config('agents.schedule_timezone'),
        ]);
    }

    /**
     * Exactly the filtered entries, newest first, as a CSV Excel opens: UTF-8
     * with a byte order mark, ";" between columns, headers in Portuguese.
     * Streamed in chunks, so a long trail never sits in memory. The export
     * is itself recorded, with the filters and how many entries it carried.
     */
    public function export(Request $request, TenantManager $tenants): StreamedResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);

        $filters = AuditFilters::fromRequest($request);
        $confidential = ConfidentialEntries::forCurrentTenant();
        $reads = ConfidentialEntries::readBy($user);

        // The set as it is now: entries recorded while the file is written (this export's own among them) stay out.
        $last = (int) $filters->apply(AuditLog::query(), $confidential, $reads)->max('id');
        $count = $filters->apply(AuditLog::query(), $confidential, $reads)->where('id', '<=', $last)->count();

        AuditLog::record($user, 'audit.exported', ['filters' => $filters->toArray(), 'count' => $count]);

        $tenant = $tenants->currentOrFail();
        $filename = 'auditoria-'.$tenant->slug.'-'.now((string) config('agents.schedule_timezone'))->format('Y-m-d-His').'.csv';
        $presenter = new AuditPresenter($confidential, $reads);

        // The body is sent after the request's middleware has finished, so the tenant is set again around it.
        return response()->streamDownload(fn () => $tenants->run($tenant->id, function () use ($filters, $confidential, $reads, $last, $presenter) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, AuditPresenter::CSV_HEADERS, ';', '"', '', "\r\n");

            $filters->apply(AuditLog::query(), $confidential, $reads)
                ->where('id', '<=', $last)
                ->chunkByIdDesc(500, function (Collection $logs) use ($out, $presenter) {
                    foreach ($presenter->csv($logs) as $line) {
                        fputcsv($out, $line, ';', '"', '', "\r\n");
                    }

                    flush();
                });

            fclose($out);
        }), $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * What the filters offer: the people and agents of the company, the
     * actions recorded so far by family, and the kinds of object.
     *
     * @return array{actors: list<array{label: string, options: list<array{value: string, label: string}>}>, actions: list<array{family: string, label: string, prefix: string, options: list<array{value: string, label: string}>}>, results: list<array{value: string, label: string}>, subjects: list<array{value: string, label: string}>}
     */
    private function options(): array
    {
        $capabilities = Capability::query()->pluck('name', 'key')->map(fn ($name) => (string) $name)->all();

        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action')->map(fn ($action) => (string) $action)
            ->groupBy(fn (string $action) => AuditVocabulary::family($action))
            ->map(fn (Collection $group, string $family) => [
                'family' => $family,
                'label' => AuditVocabulary::familyLabel($family),
                'prefix' => $family.'.',
                'options' => $group->map(fn (string $action) => ['value' => $action, 'label' => AuditVocabulary::action($action, $capabilities)])
                    ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            ])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        $kinds = AuditLog::query()->whereNotNull('subject_type')->distinct()->pluck('subject_type')
            ->map(fn ($type) => AuditVocabulary::subjectKey(Relation::getMorphedModel((string) $type) ?? (string) $type))
            ->filter()->unique()
            ->map(fn (string $key) => ['value' => $key, 'label' => AuditVocabulary::subjectLabel(AuditVocabulary::subjectClasses()[$key])])
            ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return [
            'actors' => [
                ['label' => 'Pessoas', 'options' => User::query()->orderBy('name')->get(['id', 'name'])->map(fn (User $person) => ['value' => "user:{$person->id}", 'label' => $person->name])->all()],
                ['label' => 'Agentes', 'options' => Agent::query()->orderBy('name')->get(['id', 'name'])->map(fn (Agent $agent) => ['value' => "agent:{$agent->id}", 'label' => $agent->name])->all()],
                ['label' => 'Outros', 'options' => [['value' => 'system', 'label' => 'Sistema'], ['value' => 'platform', 'label' => 'Plataforma (Rethink)']]],
            ],
            'actions' => $actions,
            'results' => array_map(fn (AuditResult $result) => ['value' => $result->value, 'label' => AuditVocabulary::result($result)], AuditResult::cases()),
            'subjects' => $kinds,
        ];
    }
}
