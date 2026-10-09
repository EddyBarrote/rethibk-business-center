<?php

namespace App\Http\Controllers;

use App\Enums\AgentStatus;
use App\Models\Agent;
use App\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Coming in lands in the person's most recent conversation (docs/DECISOES.md,
 * "Conversas a partir dos agentes"): the agents in the sidebar are the
 * conversations, and what needs the person is in the bell and the counters.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $chat = Task::query()
            ->where('chat_key', 'like', $user->id.':%')
            ->whereHas('messages')
            ->with('assigneeAgent')
            ->latest('last_activity_at')
            ->latest('id')
            ->get()
            ->first(fn (Task $task) => $task->assigneeAgent !== null && $user->can('run', $task->assigneeAgent));

        if ($chat !== null) {
            return to_route('tasks.show', $chat);
        }

        // No conversation yet: a blank one with the first agent they can talk to.
        $agent = Agent::query()->where('status', AgentStatus::Active)->orderBy('name')->get()
            ->first(fn (Agent $agent) => $user->can('run', $agent));

        return $agent !== null ? to_route('agents.conversation', $agent) : to_route('tasks.index');
    }
}
