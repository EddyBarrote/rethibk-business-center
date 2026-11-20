<?php

namespace App\Ai\Autonomy;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Runs\RoutineRunner;
use App\Enums\Permission;
use App\Enums\StepType;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Capability;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Pay, contracts, identity documents and disciplinary data (docs/DECISOES.md,
 * "Director de Recursos Humanos"). The ERP token acts as an administrator, so
 * the ERP's own per-person scoping protects nothing here.
 *
 * - Running: the capabilities in autonomy.people_data run only for a person
 *   who asked for the run and may manage people («Gerir pessoas e papéis»),
 *   or in a routine of the agent whose result goes to such a person (or to
 *   nobody). Who a task is with does not count: an email or another agent can
 *   open a task with anyone. Checked in GatedTool, before the autonomy gate.
 * - Reading what they returned: run traces show it only to people who may
 *   manage people, and the live broadcast never carries it.
 */
final class PeopleData
{
    /** What a viewer without the permission reads instead of the result. */
    public const WITHHELD = 'Resultado com dados de pessoal (salários, contratos, documentos de identificação ou processos disciplinares): só quem gere pessoas o vê.';

    public function restricted(Capability $capability): bool
    {
        return self::isPeopleData($capability->key);
    }

    /**
     * A closed list with families ("erp.hr_*") and the few of a family that stay open.
     */
    public static function isPeopleData(string $key): bool
    {
        return Str::is((array) config('autonomy.people_data', []), $key)
            && ! Str::is((array) config('autonomy.people_data_open', []), $key);
    }

    public static function mayRead(?User $user): bool
    {
        return $user !== null && $user->is_active && $user->hasPermission(Permission::ManagePeople);
    }

    /**
     * The agent holds a capability of the list, so whoever schedules it
     * decides what it reads (RoutineController).
     */
    public static function agentHolds(?Agent $agent): bool
    {
        return $agent !== null && $agent->capabilities()->pluck('key')->contains(fn (string $key) => self::isPeopleData($key));
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

        if (self::mayRead($person)) {
            return null;
        }

        $why = $person !== null
            ? "{$person->name} não tem a permissão «".Permission::ManagePeople->label().'»'
            : 'ninguém com essa permissão pediu esta execução nem é uma rotina tua para quem a tem';

        return "Recusado: «{$capability->name}» traz salários, contratos, documentos de identificação ou processos disciplinares, "
            ."que só se lêem a pedido de quem gere pessoas ou numa rotina tua para essa pessoa, e {$why}. "
            .'Não tentes de novo nem por outra ferramenta. Diz que esses dados estão no ERP para quem tem acesso, ou que quem gere pessoas os pode pedir.';
    }

    /**
     * The run is one of this agent's own routines, and its result goes to
     * someone who may manage people, or to nobody (then only to the run list).
     */
    public function isOwnRoutine(CapabilityContext $context): bool
    {
        $id = $context->run->agent_routine_id;
        $routine = $id !== null ? AgentRoutine::query()->whereKey($id)->where('agent_id', $context->agent->id)->first() : null;

        if ($routine === null) {
            return false;
        }

        $recipient = app(RoutineRunner::class)->recipient($routine, $context->agent);

        return $recipient === null || self::mayRead($recipient);
    }

    /**
     * The person the run works for: only whoever asked for it. A run without
     * one (an email, a task an agent opened, the heartbeat, a flow's step)
     * works for nobody.
     */
    public function personOf(AgentRun $run): ?User
    {
        return $run->requestedBy;
    }

    /**
     * A step as this viewer may read it: the result of a people-data
     * capability is withheld unless they may manage people. A null viewer is
     * the live broadcast, which never carries it.
     *
     * @return array<string, mixed>
     */
    public static function present(AgentRunStep $step, ?User $viewer): array
    {
        $shown = $step->toBroadcast();

        if ($step->type === StepType::ToolResult && $step->tool_name !== null && self::isPeopleData($step->tool_name) && ! self::mayRead($viewer)) {
            $shown['payload'] = [...(array) $shown['payload'], 'content' => self::WITHHELD, 'withheld' => true];
        }

        return $shown;
    }
}
