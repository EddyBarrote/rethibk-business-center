<?php

namespace App\Http\Controllers;

use App\Ai\Autonomy\ValueLimits;
use App\Models\Agent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The agent's value limit (docs/DECISOES.md, "Limite de valor por agente"),
 * set by who edits the agent, beside its definition: its own limit and,
 * optionally, one per type (compras, facturação, despesas). Audited.
 */
class AgentValueLimitController extends Controller
{
    public function update(Request $request, Agent $agent, ValueLimits $limits): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $data = $request->validate(ValueLimits::rules(), [
            'between' => 'O limite vai de 0 (sem limite) a 1 000 000 000 MZN.',
            'integer' => 'Escreva o limite em meticais, sem casas decimais.',
        ]);

        $limits->save($agent, $data, $this->user($request));

        return back()->with('success', 'Limite de valor guardado.');
    }
}
