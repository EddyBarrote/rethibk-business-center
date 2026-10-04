<?php

namespace App\Http\Controllers;

use App\Enums\TenderStatus;
use App\Models\AuditLog;
use App\Models\Tender;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenders found on the monitored portals and in emails (E03).
 */
class TenderController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $open = [TenderStatus::New, TenderStatus::Reviewing, TenderStatus::Bidding];

        return Inertia::render('Tenders/Index', [
            'tenders' => Tender::query()
                ->when($status === 'closed', fn ($q) => $q->whereNotIn('status', $open), fn ($q) => $q->whereIn('status', $open))
                ->orderByRaw('deadline_at is null')
                ->orderBy('deadline_at')
                ->latest('id')
                ->paginate(30)
                ->withQueryString()
                ->through(fn (Tender $t) => [
                    'id' => $t->id,
                    'title' => $t->title,
                    'entity' => $t->entity,
                    'reference' => $t->reference,
                    'source' => $t->source,
                    'url' => $t->url,
                    'summary' => $t->summary,
                    'deadline_at' => $t->deadline_at?->toIso8601String(),
                    'status' => $t->status->value,
                    'status_label' => $t->status->label(),
                    'email_id' => $t->email_message_id,
                    'erp_lead_id' => $t->erp_lead_id,
                    'keywords' => $t->matched_keywords ?? [],
                ]),
            'statuses' => TenderStatus::options(),
            'filter' => $status === 'closed' ? 'closed' : 'open',
        ]);
    }

    public function update(Request $request, Tender $tender): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(TenderStatus::class)]]);
        $before = $tender->status->value;
        $tender->update($data);

        AuditLog::record($this->user($request), 'tender.status_changed', ['from' => $before, 'to' => $data['status']], subject: $tender);

        return back()->with('success', 'Estado do concurso actualizado.');
    }
}
