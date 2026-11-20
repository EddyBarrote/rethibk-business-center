<?php

namespace App\Ai\Autonomy;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Runs\RoutineRunner;
use App\Enums\Permission;
use App\Enums\StepType;
use App\Models\Agent;
use App\Models\AgentCapability;
use App\Models\AgentRoutine;
use App\Models\AgentRun;
use App\Models\AgentRunStep;
use App\Models\Capability;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Pay, contracts, identity documents and disciplinary data (docs/DECISOES.md,
 * "Director de Recursos Humanos"). The ERP token acts as an administrator, so
 * the ERP's own per-person scoping protects nothing here.
 *
 * - A confidential agent (one that holds any of these capabilities) exists
 *   only for people who may manage people («Gerir pessoas e papéis»): only
 *   they talk to it, see its conversations, tasks, runs, reports and
 *   approvals, decide those approvals and receive what it sends. The owner
 *   keeps everything (the CEO role always has the permission); admins who
 *   read every conversation do not read these.
 * - Running, the second layer: the capabilities in autonomy.people_data run
 *   only for a person who asked for the run and may manage people, or in a
 *   routine of the agent whose result goes to such a person. Who a task is
 *   with does not count: an email or another agent can open a task with
 *   anyone. Checked in GatedTool, before the autonomy gate.
 * - Reading what they returned: run traces show it only to people who may
 *   manage people, and the live broadcast never carries it.
 */
final class PeopleData
{
    /** Capabilities that write for everyone to read: a confidential agent may not use them. */
    public const PUBLISHES = ['knowledge.save', 'memory.remember_decision'];

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
     * The agent holds a capability of the list: a confidential agent, which
     * exists only for people who may manage people.
     */
    public static function agentHolds(?Agent $agent): bool
    {
        return $agent !== null && $agent->capabilities()->pluck('key')->contains(fn (string $key) => self::isPeopleData($key));
    }

    /**
     * The confidential agents of the tenant, as ids. Read every time: an
     * admin may give or take a capability at any moment.
     *
     * @return list<int>
     */
    public static function confidentialAgentIds(): array
    {
        $ids = Capability::query()->get(['id', 'key'])->filter(fn (Capability $capability) => self::isPeopleData($capability->key))->modelKeys();

        return $ids === [] ? [] : AgentCapability::query()->whereIn('capability_id', $ids)->distinct()->pluck('agent_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * The confidential agents this person may not see: all of them for
     * whoever may not manage people, none for whoever may.
     *
     * @return list<int>
     */
    public static function hiddenAgentIds(?User $user): array
    {
        return self::mayRead($user) ? [] : self::confidentialAgentIds();
    }

    /**
     * The agent is confidential and this person may not manage people: they
     * neither talk to it nor see anything it produced.
     */
    public static function closedTo(?Agent $agent, ?User $user): bool
    {
        return ! self::mayRead($user) && self::agentHolds($agent);
    }

    /**
     * The task is a confidential agent's (assigned to it, or opened by it)
     * and this person may not manage people.
     */
    public static function closesTask(Task $task, ?User $user): bool
    {
        $hidden = self::hiddenAgentIds($user);

        return $hidden !== [] && (in_array($task->assignee_agent_id, $hidden, true) || in_array($task->created_by_agent_id, $hidden, true));
    }

    /**
     * Leave out what these agents made; a row with no agent stays.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<int>  $agentIds
     * @return Builder<TModel>
     */
    public static function withoutAgents(Builder $query, array $agentIds, string $column = 'agent_id'): Builder
    {
        return $agentIds === [] ? $query : $query->where(fn (Builder $q) => $q->whereNull($column)->orWhereNotIn($column, $agentIds));
    }

    /**
     * The tasks of these agents, assigned to them or opened by them, as a
     * sub-query of ids.
     *
     * @param  list<int>  $agentIds
     * @return Builder<Task>
     */
    public static function tasksOf(array $agentIds): Builder
    {
        return Task::query()->where(fn (Builder $q) => $q->whereIn('assignee_agent_id', $agentIds)->orWhereIn('created_by_agent_id', $agentIds))->select('id');
    }

    /**
     * Why these people may not be a confidential agent's chefia or talk to it,
     * for the screen.
     *
     * @param  list<string>  $names
     */
    public static function accessRefusal(Agent $agent, array $names): string
    {
        return "{$agent->name} lê salários e dados de pessoal: a chefia e quem fala com ele têm de ter a permissão «"
            .Permission::ManagePeople->label().'», e '.implode(', ', $names).(count($names) > 1 ? ' não a têm.' : ' não a tem.');
    }

    /**
     * Why a confidential agent may not send its work to this person, in words
     * it can pass on; null when it may (the agent is not confidential, or the
     * person may manage people).
     */
    public static function recipientRefusal(Agent $agent, ?User $person): ?string
    {
        if ($person === null || self::mayRead($person) || ! self::agentHolds($agent)) {
            return null;
        }

        return "não envio a {$person->name}: o meu trabalho tem dados de pessoal e só fica entre quem tem a permissão «"
            .Permission::ManagePeople->label().'». Diz a quem te pediu que o envie a quem a tem, ou deixa-o aqui.';
    }

    /**
     * Why the call may not run, in words the agent can pass on; null when it may.
     */
    public function refusal(Capability $capability, CapabilityContext $context): ?string
    {
        // What a confidential agent would write for everyone to read.
        if (in_array($capability->key, self::PUBLISHES, true) && self::agentHolds($context->agent)) {
            return "Recusado: «{$capability->name}» escreve na base de conhecimento ou na memória da empresa, que lê quem não tem a permissão «"
                .Permission::ManagePeople->label().'», e o teu trabalho tem dados de pessoal. Não tentes de novo nem por outra ferramenta: deixa-o na conversa ou num documento (reports.draft).';
        }

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
     * someone who may manage people. A routine with nobody to send it to does
     * not count: its result would go to the run list and the admins.
     */
    public function isOwnRoutine(CapabilityContext $context): bool
    {
        $id = $context->run->agent_routine_id;
        $routine = $id !== null ? AgentRoutine::query()->whereKey($id)->where('agent_id', $context->agent->id)->first() : null;

        return $routine !== null && self::mayRead(app(RoutineRunner::class)->recipient($routine, $context->agent));
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
