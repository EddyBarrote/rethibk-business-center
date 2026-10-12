<?php

namespace App\Http\Controllers\Settings;

use App\Ai\Memory\MemoryConsolidator;
use App\Enums\MemorySection;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentMemory;
use App\Models\AuditLog;
use App\Support\MemoryPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definições › Memória › A minha memória (docs/DECISOES.md, "Memória em entradas",
 * 12.10.2026), like claude.ai: the three switches, and what each agent
 * remembers of the person, in entries by section, to read, correct or delete.
 * Only the person's own memory; administrators keep theirs on the agent's page.
 */
class MemoryController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $entries = AgentMemory::query()->where('user_id', $user->id)->with(['aboutUser:id,name', 'task:id,number,title,tenant_id', 'task.tenant:id,slug'])->orderByDesc('updated_at')->get();
        $agents = Agent::query()->whereIn('id', $entries->pluck('agent_id')->unique())->orderBy('name')->get();

        return Inertia::render('Settings/Memory', [
            'prefs' => MemoryPreferences::for($user),
            'sections' => MemorySection::options(),
            'agents' => $agents->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'title' => $agent->title,
                'avatar_url' => $agent->avatarUrl(),
                'count' => $entries->where('agent_id', $agent->id)->count(),
                'updated_at' => $entries->where('agent_id', $agent->id)->max('updated_at')?->toIso8601String(),
            ])->values(),
            'entries' => $entries->map(fn (AgentMemory $memory) => Present::memory($memory))->values(),
        ]);
    }

    /**
     * One switch at a time, as the person flips it.
     */
    public function preference(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'key' => ['required', Rule::in(MemoryPreferences::KEYS)],
            'enabled' => ['required', 'boolean'],
        ]);

        $user->forceFill(['memory_preferences' => [...($user->memory_preferences ?? []), $data['key'] => (bool) $data['enabled']]])->save();
        AuditLog::record($user, 'memory.preference_updated', $data, subject: $user);

        return back();
    }

    public function update(Request $request, AgentMemory $memory, MemoryConsolidator $consolidator): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($memory->user_id === $user->id, 404);

        $data = $request->validate([
            ...AgentMemory::rules(),
            'kind' => ['sometimes', Rule::in([AgentMemory::WORK, AgentMemory::PERSONAL])],
        ]);

        if (MemorySection::from($data['section'])->aboutYou()) {
            $data['kind'] = AgentMemory::PERSONAL;
        }

        $wasWork = $memory->kind === AgentMemory::WORK;
        $memory->fill([...$data, 'edited_by_user_id' => $user->id])->save();
        AuditLog::record($user, 'agent.memory_updated', ['memory_id' => $memory->id], subject: $memory->agent);

        if ($wasWork || $memory->kind === AgentMemory::WORK) {
            $consolidator->publish($memory->agent);
        }

        return back()->with('success', 'Memória corrigida.');
    }

    public function destroy(Request $request, AgentMemory $memory, MemoryConsolidator $consolidator): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($memory->user_id === $user->id, 404);

        AuditLog::record($user, 'agent.memory_deleted', ['memory_id' => $memory->id, 'title' => $memory->title, 'content' => $memory->content], subject: $memory->agent);
        $memory->delete();
        $consolidator->publish($memory->agent);

        return back()->with('success', 'Esquecido.');
    }

    /**
     * Everything one agent remembers of the person.
     */
    public function forget(Request $request, Agent $agent, MemoryConsolidator $consolidator): RedirectResponse
    {
        $user = $this->user($request);
        $count = AgentMemory::query()->where('agent_id', $agent->id)->where('user_id', $user->id)->delete();

        AuditLog::record($user, 'agent.memory_forgotten', ['agent_id' => $agent->id, 'entries' => $count], subject: $agent);
        $consolidator->publish($agent);

        return back()->with('success', "{$agent->name} esqueceu o que sabia de si.");
    }
}
