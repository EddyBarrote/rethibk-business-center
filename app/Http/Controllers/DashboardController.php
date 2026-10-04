<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\Approval;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Dashboard', [
            'approvals' => Approval::query()->visibleTo($user)->pending()->with(['agent', 'assignedTo:id,name', 'decidedBy:id,name'])->latest('id')->limit(5)->get()
                ->map(fn (Approval $approval) => Present::approval($approval, $user->can('decide', $approval))),
            'agents' => Agent::query()->where('status', '!=', AgentStatus::Draft)->with(['department:id,name', 'reportsTo:id,name'])->orderBy('name')->get()
                ->map(fn (Agent $agent) => Present::agent($agent)),
            'runs' => AgentRun::query()->with(['agent:id,name', 'requestedBy:id,name'])->latest('id')->limit(8)->get()
                ->map(fn (AgentRun $run) => Present::run($run)),
        ]);
    }
}
