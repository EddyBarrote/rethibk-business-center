<?php

namespace App\Http\Controllers\Settings;

use App\Enums\MemorySection;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Presenters\Present;
use App\Models\Agent;
use App\Models\AgentMemory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Definições › Memória › Memória dos agentes (docs/DECISOES.md, "Memória em
 * entradas", 12.10.2026): what each agent remembers of each person, for whoever
 * may correct any agent's memory. Moved out of the agent's page, so memory has
 * its own place in the settings. Changes go through AgentController's routes.
 */
class AgentMemoryController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);
        abort_unless($user->hasPermission(Permission::ManageAgentMemory), 403);

        $counts = AgentMemory::query()->selectRaw('agent_id, count(*) as entries, max(updated_at) as updated_at')->groupBy('agent_id')->get()->keyBy('agent_id');
        $agents = Agent::query()->orderBy('name')->get()->filter(fn (Agent $agent) => $user->can('viewMemory', $agent))->values();
        $chosen = $agents->firstWhere('id', (int) $request->query('agente'))
            ?? $agents->first(fn (Agent $agent) => $counts->has($agent->id))
            ?? $agents->first();

        return Inertia::render('Settings/AgentMemory', [
            'agents' => $agents->map(fn (Agent $agent) => [
                'id' => $agent->id,
                'name' => $agent->name,
                'avatar_url' => $agent->avatarUrl(),
                'count' => (int) ($counts->get($agent->id)->entries ?? 0),
            ]),
            'agent_id' => $chosen?->id,
            'sections' => MemorySection::options(),
            'entries' => $chosen === null ? [] : AgentMemory::query()->where('agent_id', $chosen->id)
                ->with(['user:id,name', 'aboutUser:id,name', 'task:id,number,title,tenant_id', 'task.tenant:id,slug'])
                ->orderByDesc('updated_at')->limit(500)->get()
                ->map(fn (AgentMemory $memory) => Present::memory($memory)),
        ]);
    }
}
