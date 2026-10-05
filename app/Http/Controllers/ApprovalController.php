<?php

namespace App\Http\Controllers;

use App\Ai\Runs\ApprovalService;
use App\Http\Presenters\Present;
use App\Models\Approval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use LogicException;

/**
 * The approval queue (section 12.2). Owners and admins see everything; other
 * people see the approvals of agents they answer for.
 */
class ApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        $status = $request->query('status', 'pending');

        $approvals = Approval::query()
            ->with(Present::APPROVAL_RELATIONS)
            ->visibleTo($user)
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Approvals/Index', [
            'approvals' => $approvals->through(fn (Approval $approval) => Present::approval($approval, $user->can('decide', $approval))),
            'filters' => ['status' => $status],
            'counts' => [
                'pending' => Approval::query()->visibleTo($user)->pending()->count(),
            ],
        ]);
    }

    public function approve(Request $request, Approval $approval, ApprovalService $approvals): RedirectResponse
    {
        Gate::authorize('decide', $approval);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);

        try {
            $approvals->approve($approval, $this->user($request), $data['note'] ?? null);
        } catch (LogicException) {
            return back()->with('error', 'Esta acção já tinha sido decidida.');
        }

        return back()->with('success', 'Aprovado. A acção vai ser executada.');
    }

    /**
     * Approves several pending actions at once, each under the same rule as one by one. Actions under the absolute
     * ceiling (payments, external email, contracts…) are never approved in bulk: they are decided one at a time.
     */
    public function approveMany(Request $request, ApprovalService $approvals): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate(['ids' => ['required', 'array', 'max:50'], 'ids.*' => ['integer']]);
        $approved = 0;

        foreach (Approval::query()->with('agent')->whereIn('id', $data['ids'])->pending()->whereNull('ceiling_reason')->get() as $approval) {
            if (! $user->can('decide', $approval)) {
                continue;
            }

            try {
                $approvals->approve($approval, $user, null);
                $approved++;
            } catch (LogicException) {
                // Decided by someone else in the meantime.
            }
        }

        return back()->with($approved > 0 ? 'success' : 'error', match ($approved) {
            0 => 'Nenhuma acção aprovada: já estavam decididas ou não lhe cabem.',
            1 => 'Aprovada 1 acção. Vai ser executada.',
            default => "Aprovadas {$approved} acções. Vão ser executadas.",
        });
    }

    public function reject(Request $request, Approval $approval, ApprovalService $approvals): RedirectResponse
    {
        Gate::authorize('decide', $approval);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);

        try {
            $approvals->reject($approval, $this->user($request), $data['note']);
        } catch (LogicException) {
            return back()->with('error', 'Esta acção já tinha sido decidida.');
        }

        return back()->with('success', 'Rejeitado.');
    }
}
