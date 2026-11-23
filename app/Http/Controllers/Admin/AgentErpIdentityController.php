<?php

namespace App\Http\Controllers\Admin;

use App\Erp\AgentErpIdentities;
use App\Models\Agent;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The super admin sets an agent's own identity in the ERP, as the tenant's
 * admins do in Agentes › Editar (docs/DECISOES.md, "Identidade própria de cada
 * agente no ERP"). SetTenantFromRoute puts these routes inside {tenant}.
 */
class AgentErpIdentityController extends AdminController
{
    public function __construct(private readonly AgentErpIdentities $identities) {}

    public function update(Request $request, Tenant $tenant, Agent $agent): RedirectResponse
    {
        $data = $request->validate(AgentErpIdentities::rules());
        $this->identities->set($agent, $data['token'], $this->admin($request));

        return back()->with('success', "Token do ERP de {$agent->name} guardado.");
    }

    public function destroy(Request $request, Tenant $tenant, Agent $agent): RedirectResponse
    {
        $this->identities->remove($agent, $this->admin($request));

        return back()->with('success', "{$agent->name} volta a usar o token da ligação ao ERP.");
    }

    public function test(Request $request, Tenant $tenant, Agent $agent): RedirectResponse
    {
        $identity = $this->identities->test($agent, $this->admin($request)) ?? abort(404);

        return ($identity->last_test['ok'] ?? false)
            ? back()->with('success', 'Identidade testada: o token vê '.count($identity->last_test['tools'] ?? []).' ferramentas do ERP.')
            : back()->with('error', 'O teste da identidade no ERP falhou: '.($identity->last_test['error'] ?? ''));
    }
}
