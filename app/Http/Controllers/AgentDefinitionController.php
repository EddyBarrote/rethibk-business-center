<?php

namespace App\Http\Controllers;

use App\Ai\Agents\AgentAvatars;
use App\Ai\Agents\AgentDrafting;
use App\Ai\Agents\AgentEditor;
use App\Ai\Budget\BudgetExceeded;
use App\Ai\Runs\MissingProviderKey;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Owners and admins create and edit their own colleagues (docs/CAPACIDADES.md),
 * optionally starting from a draft the AI writes from a description.
 */
class AgentDefinitionController extends Controller
{
    public function __construct(private readonly AgentEditor $editor) {}

    public function create(Request $request): Response
    {
        Gate::authorize('create', Agent::class);

        return Inertia::render('Agents/Form', [
            ...$this->editor->options(),
            'agent' => null,
            'draft' => $request->session()->get('agent_draft'),
            'can_generate_avatar' => AgentAvatars::canGenerate(),
        ]);
    }

    /**
     * The assistant: describe the colleague, get a draft back in the form.
     */
    public function draft(Request $request, AgentDrafting $drafting): RedirectResponse
    {
        Gate::authorize('create', Agent::class);

        $data = $request->validate(['brief' => ['required', 'string', 'min:15', 'max:4000']]);

        try {
            $draft = $drafting->draft($data['brief']);
        } catch (MissingProviderKey|BudgetExceeded $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->withInput()->with('error', 'O assistente não conseguiu preparar a proposta: '.Str::limit($e->getMessage(), 200));
        }

        return to_route('agents.create')->with('agent_draft', $draft)->with('success', 'Proposta pronta. Reveja e guarde.');
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Agent::class);

        $agent = $this->editor->save(new Agent, $request->validate($this->editor->rules()), $this->user($request));

        return to_route('agents.edit', $agent)->with('success', "{$agent->name} faz agora parte da equipa.");
    }

    public function edit(Agent $agent): Response
    {
        Gate::authorize('update', $agent);

        return Inertia::render('Agents/Form', [
            ...$this->editor->options($agent),
            'agent' => $this->editor->present($agent),
            'draft' => null,
            'can_generate_avatar' => AgentAvatars::canGenerate(),
        ]);
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $this->editor->save($agent, $request->validate($this->editor->rules($agent)), $this->user($request));

        return back()->with('success', 'Agente guardado.');
    }
}
