<?php

namespace App\Http\Controllers;

use App\Erp\AgentErpIdentities;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * An agent's own identity in the ERP (docs/DECISOES.md, "Identidade própria de
 * cada agente no ERP"), set by whoever configures agents (agents.manage). The
 * super admin does the same in the console (Admin\AgentErpIdentityController).
 */
class AgentErpIdentityController extends Controller
{
    public function __construct(private readonly AgentErpIdentities $identities) {}

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $data = $request->validate(AgentErpIdentities::rules());
        $this->identities->set($agent, $data['token'], $this->user($request));

        return back()->with('success', "Token do ERP de {$agent->name} guardado. Teste a identidade para ver quem é no ERP.");
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $this->identities->remove($agent, $this->user($request));

        return back()->with('success', "{$agent->name} volta a usar o token da ligação ao ERP.");
    }

    public function test(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $identity = $this->identities->test($agent, $this->user($request)) ?? abort(404);

        return ($identity->last_test['ok'] ?? false)
            ? back()->with('success', 'Identidade testada: o token vê '.count($identity->last_test['tools'] ?? []).' ferramentas do ERP.')
            : back()->with('error', 'O teste da identidade no ERP falhou: '.($identity->last_test['error'] ?? ''));
    }
}
