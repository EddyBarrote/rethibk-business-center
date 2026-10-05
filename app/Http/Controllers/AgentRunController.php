<?php

namespace App\Http\Controllers;

use App\Http\Presenters\Present;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Approval;
use App\Models\Capability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Run history and the live timeline of one run (section 11.2).
 */
class AgentRunController extends Controller
{
    public function index(Request $request): Response
    {
        $runs = AgentRun::query()
            ->with(['agent:id,name', 'requestedBy:id,name'])
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Runs/Index', [
            'runs' => $runs->through(fn (AgentRun $run) => Present::run($run)),
            'filters' => ['status' => $request->query('status')],
            // Requests written for agents name tools by key; the list shows their names.
            'tool_names' => Capability::query()->pluck('name', 'key'),
        ]);
    }

    public function show(Request $request, AgentRun $run): Response
    {
        Gate::authorize('view', $run->agent);
        $user = $this->user($request);

        return Inertia::render('Runs/Show', [
            'run' => Present::run($run->load(['agent', 'requestedBy'])),
            'steps' => $run->steps()->orderBy('seq')->get()->map(fn (AgentRunStep $step) => $step->toBroadcast()),
            'approvals' => $run->approvals()->with(Present::APPROVAL_RELATIONS)->get()
                ->map(fn (Approval $approval) => Present::approval($approval, $user->can('decide', $approval))),
            // The timeline names each tool as people read it in Capacidades; the key stays in a tooltip.
            'tool_names' => Capability::query()->pluck('name', 'key'),
        ]);
    }
}
