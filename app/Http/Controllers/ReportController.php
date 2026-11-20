<?php

namespace App\Http\Controllers;

use App\Ai\Autonomy\PeopleData;
use App\Ai\Capabilities\Local\DraftReport;
use App\Documents\DocumentFormat;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\GeneratedDocument;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Documents agents drafted for review: month-close packs, comparison maps,
 * payroll checks, client sheets, meeting briefs.
 */
class ReportController extends Controller
{
    /**
     * Reports live in Documentos now (docs/DECISOES.md, "Documentos:
     * relatórios e ficheiros juntos"); the old address opens them there.
     */
    public function index(): RedirectResponse
    {
        return to_route('documents.index', ['format' => 'relatorio']);
    }

    public function show(Request $request, Report $report): Response
    {
        abort_unless($this->visible($this->user($request))->whereKey($report->id)->exists(), 403);

        return Inertia::render('Reports/Show', [
            'report' => [
                ...$this->present($report->load(['agent:id,name', 'reviewer:id,name', 'folder:id,name'])),
                'content' => $report->content,
                'data' => $report->data,
                'folder' => $report->folder ? ['id' => $report->folder->id, 'name' => $report->folder->name] : null,
                // The files exported from it, newest first.
                'files' => $report->files()->latest('id')->get(['id', 'title', 'format', 'created_at'])
                    ->map(fn (GeneratedDocument $file) => ['id' => $file->id, 'title' => $file->title, 'format' => $file->format, 'created_at' => $file->created_at->toIso8601String()]),
            ],
            'formats' => DocumentFormat::options(),
        ]);
    }

    public function review(Request $request, Report $report): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($this->visible($user)->whereKey($report->id)->exists(), 403);

        $report->forceFill(['status' => 'reviewed', 'reviewed_by_user_id' => $user->id, 'reviewed_at' => now()])->save();
        AuditLog::record($user, 'report.reviewed', ['type' => $report->type, 'title' => $report->title], subject: $report);

        return back()->with('success', 'Marcado como revisto.');
    }

    public function canSee(User $user, Report $report): bool
    {
        return $this->visible($user)->whereKey($report->id)->exists();
    }

    /**
     * Owners and admins see everything; others, documents from agents of
     * their department or that report to them.
     *
     * @return Builder<Report>
     */
    public function visible(User $user): Builder
    {
        // A confidential agent's documents only for people who may manage people (PeopleData).
        return PeopleData::withoutAgents(Report::query(), PeopleData::hiddenAgentIds($user))->when(! $user->hasPermission(Permission::ReadAllReports), fn ($q) => $q->whereHas('agent', fn ($a) => $a
            ->where('reports_to_user_id', $user->id)
            ->when($user->isManager() && $user->department_id !== null, fn ($w) => $w->orWhere('department_id', $user->department_id))));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Report $r): array
    {
        return [
            'id' => $r->id,
            'type' => $r->type,
            'type_label' => DraftReport::TYPES[$r->type] ?? $r->type,
            'title' => $r->title,
            'agent' => $r->agent?->name,
            'run_id' => $r->agent_run_id,
            'subject_ref' => $r->subject_ref,
            'period' => $r->period_start ? $r->period_start->toDateString().($r->period_end ? ' a '.$r->period_end->toDateString() : '') : null,
            'status' => $r->status,
            'reviewed_by' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
