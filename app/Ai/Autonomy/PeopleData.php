<?php

namespace App\Ai\Autonomy;

use App\Ai\Capabilities\CapabilityContext;
use App\Enums\Permission;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\Capability;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Pay, contracts, identity documents and disciplinary data (docs/DECISOES.md,
 * "Director de Recursos Humanos"). The ERP token acts as an administrator, so
 * the ERP's own per-person scoping protects nothing here: the capabilities
 * in autonomy.people_data run only in a routine of the agent itself, or for a
 * person with the «Gerir pessoas» permission. A run that is neither, such as
 * one started by an email, is refused. Checked on the server before the
 * autonomy gate (GatedTool), whatever the prompt says.
 */
final class PeopleData
{
    public function restricted(Capability $capability): bool
    {
        return Str::is((array) config('autonomy.people_data', []), $capability->key);
    }

    /**
     * Why the call may not run, in words the agent can pass on; null when it may.
     */
    public function refusal(Capability $capability, CapabilityContext $context): ?string
    {
        if (! $this->restricted($capability) || $this->isOwnRoutine($context)) {
            return null;
        }

        $person = $this->personOf($context->run);

        if ($person !== null && $person->is_active && $person->hasPermission(Permission::ManagePeople)) {
            return null;
        }

        $why = $person !== null
            ? "{$person->name} não tem a permissão «".Permission::ManagePeople->label().'»'
            : 'esta execução não é a pedido de ninguém nem é uma rotina tua';

        return "Recusado: «{$capability->name}» traz salários, contratos, documentos de identificação ou processos disciplinares, "
            ."que só se lêem numa rotina tua ou a pedido de quem gere pessoas, e {$why}. "
            .'Não tentes de novo nem por outra ferramenta. Diz que esses dados estão no ERP para quem tem acesso, ou que quem gere pessoas os pode pedir.';
    }

    /**
     * The run is one of this agent's own routines.
     */
    public function isOwnRoutine(CapabilityContext $context): bool
    {
        $routine = $context->run->agent_routine_id;

        return $routine !== null && AgentRoutine::query()->whereKey($routine)->where('agent_id', $context->agent->id)->exists();
    }

    /**
     * The person the run works for: whoever asked for it, else the person of
     * its task or conversation (who a delegated task was opened for).
     */
    public function personOf(AgentRun $run): ?User
    {
        return $run->requestedBy ?? $run->task?->user;
    }
}
