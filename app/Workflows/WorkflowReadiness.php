<?php

namespace App\Workflows;

use App\Ai\Capabilities\CapabilityRegistry;
use App\Ai\Capabilities\Local\SendEmail;
use App\Ai\Runs\AgentDirectory;
use App\Ai\Skills\AgentSkills;
use App\Models\Agent;
use App\Models\Capability;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What each block of a flow will do with what the agents have today
 * (docs/DECISOES.md, "Fluxos de trabalho"): the capability given and
 * available, the skill attached, the agent's trust level against the
 * capability's risk, and the absolute ceiling. The same rules as the
 * autonomy gate (AutonomyGate, ApprovalService::reviewerFor), applied ahead
 * of time:
 *
 * - alone: the agent does it on its own;
 * - chief: it needs an approval the Chief of Staff can give;
 * - person: a person decides (ceiling, approval block, or no Chief of Staff);
 * - missing: nobody has what it takes;
 * - flow: a block of the flow itself (trigger, loop, wait, end).
 */
final class WorkflowReadiness
{
    public const ALONE = 'alone';

    public const CHIEF = 'chief';

    public const PERSON = 'person';

    public const MISSING = 'missing';

    public const FLOW = 'flow';

    /** @var array<int, Collection<int, string>> */
    private array $agentCapabilities = [];

    /** @var array<int, list<string>> */
    private array $agentSkills = [];

    /** @var Collection<string, Capability>|null */
    private ?Collection $catalog = null;

    private Agent|false|null $chief = false;

    public function __construct(
        private readonly AgentDirectory $agents,
        private readonly AgentSkills $skills,
    ) {}

    /**
     * @return array<string, array{state: string, reasons: list<string>, fixes: list<string>, agent: string|null}>
     */
    public function check(WorkflowGraph $graph, ?Agent $agent): array
    {
        $result = [];

        foreach ($graph->nodes() as $id => $node) {
            $result[(string) $id] = $this->forNode($graph, (string) $id, $agent);
        }

        return $result;
    }

    /**
     * How many work blocks end in each state.
     *
     * @param  array<string, array{state: string}>  $readiness
     * @return array{alone: int, chief: int, person: int, missing: int}
     */
    public static function summary(array $readiness): array
    {
        $counts = [self::ALONE => 0, self::CHIEF => 0, self::PERSON => 0, self::MISSING => 0];

        foreach ($readiness as $row) {
            if (isset($counts[$row['state']])) {
                $counts[$row['state']]++;
            }
        }

        return $counts;
    }

    /**
     * @return array{state: string, reasons: list<string>, fixes: list<string>, agent: string|null}
     */
    public function forNode(WorkflowGraph $graph, string $id, ?Agent $agent): array
    {
        $type = $graph->type($id);

        return match ($type) {
            WorkflowGraph::AGENT, WorkflowGraph::CONDITION => $this->agentWork($graph, $id, $agent),
            WorkflowGraph::HANDOFF => $this->agentWork($graph, $id, Agent::query()->find((int) $graph->data($id, 'agent_id'))),
            WorkflowGraph::LOOP, WorkflowGraph::REPEAT => $agent === null
                ? $this->row(self::MISSING, null, ['Escolha o agente que trata o fluxo.'], ['choose_agent'])
                : $this->row(self::FLOW, $agent->name, ['O agente lista os itens e a plataforma repete os blocos de dentro.']),
            WorkflowGraph::APPROVAL, WorkflowGraph::PERSON => $this->person($graph, $id),
            default => $this->row(self::FLOW, null, []),
        };
    }

    /**
     * @return array{state: string, reasons: list<string>, fixes: list<string>, agent: string|null}
     */
    private function agentWork(WorkflowGraph $graph, string $id, ?Agent $agent): array
    {
        if ($agent === null) {
            return $this->row(self::MISSING, null, [$graph->type($id) === WorkflowGraph::HANDOFF ? 'Escolha o agente a quem o passo passa.' : 'Escolha o agente que trata o fluxo.'], ['choose_agent']);
        }

        if (! $agent->isActive()) {
            return $this->row(self::MISSING, $agent->name, ["O {$agent->name} não está activo."], ['activate_agent']);
        }

        $reasons = [];
        $skill = $graph->data($id, 'skill');

        if ($skill !== null && ! in_array($skill, $this->skillsOf($agent), true)) {
            return $this->row(self::MISSING, $agent->name, ["O {$agent->name} não tem a skill «{$skill}»."], ['attach_skill']);
        }

        $key = $graph->data($id, 'capability');

        if ($key === null) {
            return $this->row(self::ALONE, $agent->name, ['Sem capacidade indicada: o agente faz com o que tem.']);
        }

        $capability = $this->catalog()->get((string) $key);

        if ($capability === null || ! $capability->is_enabled) {
            return $this->row(self::MISSING, $agent->name, ["A capacidade {$key} não existe no catálogo."], ['ask_rethink']);
        }

        if (! $capability->is_available) {
            return $this->row(self::MISSING, $agent->name, ["«{$capability->name}» não está disponível agora (integração desligada ou com erro)."], ['check_integration']);
        }

        if (! $this->capabilitiesOf($agent)->contains($capability->key)) {
            return $this->row(self::MISSING, $agent->name, ["O {$agent->name} não tem a capacidade «{$capability->name}»."], ['give_capability']);
        }

        $ceiling = config('autonomy.ceiling.'.$capability->key);

        if ($ceiling !== null) {
            return $this->row(self::PERSON, $agent->name, ["Tecto absoluto: {$ceiling}. Decide sempre uma pessoa."]);
        }

        if ($capability->key === 'comms.send_email') {
            $text = $graph->label($id).' '.$graph->data($id, 'instruction', '');

            if (SendEmail::mentionsProposal($text)) {
                return $this->row(self::PERSON, $agent->name, ['Proposta com preço para fora: decide sempre uma pessoa.']);
            }

            $reasons[] = 'Para uma entidade nova, decide uma pessoa.';
        }

        if (! $capability->is_mutating || $agent->autonomy_level->value >= $capability->risk->value) {
            return $this->row(self::ALONE, $agent->name, ["«{$capability->name}» cabe no nível {$agent->autonomy_level->code()} do agente.", ...$reasons]);
        }

        $chief = $this->chief();
        $need = "«{$capability->name}» pede {$capability->risk->code()} e o {$agent->name} está em {$agent->autonomy_level->code()}.";

        if ($chief !== null && $chief->id !== $agent->id && $chief->autonomy_level->value >= $capability->risk->value) {
            return $this->row(self::CHIEF, $agent->name, [$need.' O Chief of Staff revalida.', ...$reasons], ['raise_level']);
        }

        return $this->row(self::PERSON, $agent->name, [$need.' Decide uma pessoa.', ...$reasons], ['raise_level']);
    }

    /**
     * @return array{state: string, reasons: list<string>, fixes: list<string>, agent: string|null}
     */
    private function person(WorkflowGraph $graph, string $id): array
    {
        $person = User::query()->find((int) $graph->data($id, 'user_id'));

        return $this->row(self::PERSON, null, [$person !== null ? "Decide {$person->name}." : 'Decide a pessoa de recurso do fluxo.']);
    }

    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $fixes
     * @return array{state: string, reasons: list<string>, fixes: list<string>, agent: string|null}
     */
    private function row(string $state, ?string $agent, array $reasons, array $fixes = []): array
    {
        return ['state' => $state, 'reasons' => $reasons, 'fixes' => $fixes, 'agent' => $agent];
    }

    /**
     * The keys the agent can use: its enabled capabilities and those every agent gets (ToolResolver).
     *
     * @return Collection<int, string>
     */
    private function capabilitiesOf(Agent $agent): Collection
    {
        return $this->agentCapabilities[$agent->id] ??= $agent->capabilities()
            ->wherePivot('enabled', true)
            ->pluck('key')
            ->merge(CapabilityRegistry::alwaysOn())
            ->values();
    }

    /**
     * @return list<string>
     */
    private function skillsOf(Agent $agent): array
    {
        return $this->agentSkills[$agent->id] ??= $this->skills->for($agent)->pluck('key')->map(fn ($key) => (string) $key)->values()->all();
    }

    /**
     * @return Collection<string, Capability>
     */
    private function catalog(): Collection
    {
        return $this->catalog ??= Capability::query()->get()->keyBy('key');
    }

    private function chief(): ?Agent
    {
        if ($this->chief === false) {
            $this->chief = $this->agents->forRole('chief_of_staff');
        }

        return $this->chief;
    }
}
