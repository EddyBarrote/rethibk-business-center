<?php

namespace App\Http\Controllers;

use App\Ai\Runs\AgentNow;
use App\Attachments\AttachmentLimits;
use App\Enums\AgentStatus;
use App\Enums\QuestionStatus;
use App\Home\MorningSummary;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentQuestion;
use App\Models\Approval;
use App\Models\Task;
use App\Support\AgentSuggestions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The entry screen (docs/DECISOES.md, "Ecrã de entrada e modo lite"): a
 * greeting, a box to write in, the agent to write to (the one of the most
 * recent conversation) and what to ask it. Sending opens the conversation.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $this->user($request);

        $agents = Agent::query()
            ->where('status', AgentStatus::Active)
            ->with(['capabilities' => fn ($query) => $query->wherePivot('enabled', true)])
            ->orderBy('name')
            ->get()
            ->filter(fn (Agent $agent) => $user->can('run', $agent))
            ->values();

        // Nobody to talk to: the tasks.
        if ($agents->isEmpty()) {
            return to_route('tasks.index');
        }

        $latest = Task::query()
            ->where('chat_key', 'like', $user->id.':%')
            ->whereIn('assignee_agent_id', $agents->pluck('id'))
            ->whereHas('messages')
            ->latest('last_activity_at')
            ->latest('id')
            ->value('assignee_agent_id');

        $questions = AgentQuestion::query()
            ->where('asked_user_id', $user->id)
            ->where('status', QuestionStatus::Pending)
            ->whereNotNull('task_id')
            ->with('agent:id,name')
            ->latest('id')
            ->get()
            ->unique('agent_id')
            ->map(fn (AgentQuestion $question) => [
                'agent' => $question->agent->name,
                'href' => "/tasks/{$question->task_id}",
            ])
            ->values();

        return Inertia::render('Home', [
            'agents' => $agents->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'title' => $agent->title,
                'avatar_url' => $agent->avatarUrl(),
                'suggestions' => AgentSuggestions::for($agent),
            ]),
            'agent_id' => $latest ?? $agents->first()->id,
            'questions' => $questions,
            // "À sua espera" opens these in a side panel, to decide without leaving the screen.
            'approvals' => fn () => Approval::query()->visibleTo($user)->pending()->with(Present::APPROVAL_RELATIONS)->latest('id')->limit(20)->get()
                ->map(fn (Approval $approval) => Present::approval($approval, $user->can('decide', $approval))),
            'attachment_limits' => AttachmentLimits::current()->forScreen(),
            // Who is working now and what they finished today (docs/DECISOES.md, "Início: os agentes a trabalhar").
            'agents_now' => fn () => app(AgentNow::class)->overview($agents, $user),
            // Once a day, until closed (docs/DECISOES.md, "Resumo da manhã").
            'morning' => fn () => app(MorningSummary::class)->for($user),
        ]);
    }

    /** «Fechar» on the morning summary: it comes back tomorrow, on any device. */
    public function closeMorning(Request $request, MorningSummary $summary): RedirectResponse
    {
        $summary->close($this->user($request));

        return back();
    }
}
