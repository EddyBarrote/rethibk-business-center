<?php

namespace App\Workflows;

use App\Ai\Agents\AgentDrafting;
use App\Ai\Budget\BudgetExceeded;
use App\Ai\Budget\BudgetGuard;
use App\Ai\Runs\MissingProviderKey;
use App\Enums\AgentStatus;
use App\Enums\EmailCategory;
use App\Enums\WorkflowTrigger;
use App\Models\Agent;
use App\Models\Capability;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;

/**
 * Turns a description into a flow's draft with WorkflowDrafter. Positions are
 * left at zero: the editor lays the blocks out. Nothing is saved here.
 */
final class WorkflowDrafting
{
    public function __construct(private readonly BudgetGuard $budget) {}

    /**
     * The draft the person confirms on a card. The trigger and the kind of
     * email they already chose are kept; the agent is the drafter's pick
     * among the active ones, else the suggested one.
     *
     * @return array{name: string, description: string, trigger: string, email_category: ?string, agent_id: ?int, notes: list<string>, graph: array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}}
     */
    public function draft(string $description, ?Agent $agent, ?EmailCategory $category, ?WorkflowTrigger $trigger = null): array
    {
        if (! WorkflowDrafter::isFaked() && ! AgentDrafting::available()) {
            throw new MissingProviderKey('Falta a chave da API do provedor '.config('ai.default').' no ficheiro .env.');
        }

        if ($this->budget->budget()->tenantMonthly !== null && $this->budget->tenantSpent() >= $this->budget->tenantCap()) {
            throw new BudgetExceeded('tenant', 'O orçamento mensal de IA da organização está esgotado.');
        }

        $trigger = $category !== null ? WorkflowTrigger::Email : $trigger;

        $response = (new WorkflowDrafter($this->context($agent, $category, $trigger)))
            ->prompt("O fluxo de que preciso:\n".$description, provider: config('ai.default'), model: config('agents.model') ?: null);

        $draft = $response instanceof StructuredAgentResponse ? $response->toArray() : (array) json_decode($response->text, true);

        $trigger ??= WorkflowTrigger::tryFrom((string) ($draft['trigger'] ?? '')) ?? WorkflowTrigger::Email;
        $category = $trigger === WorkflowTrigger::Email ? ($category ?? EmailCategory::tryFrom((string) ($draft['email_category'] ?? ''))) : null;
        $chosen = Agent::query()->where('status', AgentStatus::Active)->where('key', (string) ($draft['agent'] ?? ''))->value('id');

        return [
            'name' => Str::limit(trim((string) ($draft['name'] ?? '')), 200, '') ?: ($category ? 'Responder a '.Str::lcfirst($category->label()) : 'Novo fluxo'),
            'description' => Str::limit(trim((string) ($draft['summary'] ?? '')), 4000, ''),
            'trigger' => $trigger->value,
            'email_category' => $category?->value,
            'agent_id' => $chosen ?? $agent?->id,
            'notes' => array_values(array_slice(array_filter(array_map(fn ($note) => is_string($note) ? Str::limit(trim($note), 300) : '', (array) ($draft['notes'] ?? []))), 0, 8)),
            'graph' => $this->graph((array) ($draft['steps'] ?? []), $category, $trigger),
        ];
    }

    /**
     * @param  list<mixed>  $steps
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function graph(array $steps, ?EmailCategory $category, WorkflowTrigger $trigger = WorkflowTrigger::Email): array
    {
        $agents = Agent::query()->get(['id', 'key'])->pluck('id', 'key');
        $refs = [];
        $nodes = [['id' => 'trigger', 'type' => WorkflowGraph::TRIGGER, 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => $category?->label() ?? $trigger->label()]]];
        $edges = [];
        $hasEnd = false;

        foreach (array_slice(array_values(array_filter($steps, 'is_array')), 0, 30) as $i => $step) {
            $ref = Str::limit(Str::slug((string) ($step['ref'] ?? "passo_{$i}"), '_') ?: "passo_{$i}", 40, '');
            $refs[(string) ($step['ref'] ?? '')] = $ref;
        }

        $end = function () use (&$nodes, &$hasEnd): string {
            if (! $hasEnd) {
                $nodes[] = ['id' => 'end', 'type' => WorkflowGraph::END, 'position' => ['x' => 0, 'y' => 0], 'data' => ['label' => 'Fim']];
                $hasEnd = true;
            }

            return 'end';
        };
        $target = fn (?string $ref) => $ref === null || $ref === '' ? null : ($ref === 'end' ? $end() : ($refs[$ref] ?? null));

        $first = null;
        $inside = [];

        foreach (array_slice(array_values(array_filter($steps, 'is_array')), 0, 30) as $step) {
            $raw = $step['type'] ?? null;
            $type = is_string($raw) && in_array($raw, WorkflowGraph::TYPES, true) && $raw !== WorkflowGraph::TRIGGER ? $raw : WorkflowGraph::AGENT;
            $id = $refs[(string) ($step['ref'] ?? '')] ?? null;

            if ($id === null) {
                continue;
            }

            $parent = isset($step['inside']) ? ($refs[(string) $step['inside']] ?? null) : null;
            $inside[$id] = $parent;
            $first ??= $parent === null && $type !== WorkflowGraph::END ? $id : null;

            $nodes[] = array_filter([
                'id' => $id,
                'type' => $type,
                'position' => ['x' => 0, 'y' => 0],
                'parentId' => $parent,
                'data' => array_filter([
                    'label' => Str::limit((string) ($step['label'] ?? 'Passo'), 200, ''),
                    'instruction' => isset($step['instruction']) ? Str::limit((string) $step['instruction'], 2000, '') : null,
                    'capability' => Capability::query()->where('key', (string) ($step['capability'] ?? ''))->exists() ? (string) $step['capability'] : null,
                    'agent_id' => $type === WorkflowGraph::HANDOFF ? ($agents[(string) ($step['agent'] ?? '')] ?? null) : null,
                    'items' => $type === WorkflowGraph::LOOP ? (string) ($step['items'] ?? '') : null,
                    'until' => $type === WorkflowGraph::REPEAT ? (string) ($step['until'] ?? '') : null,
                    'max' => in_array($type, [WorkflowGraph::LOOP, WorkflowGraph::REPEAT], true) ? max(1, min(WorkflowGraph::MAX_ITERATIONS, (int) ($step['max'] ?? 5))) : null,
                    'hours' => $type === WorkflowGraph::WAIT ? max(1, min(720, (int) ($step['hours'] ?? 24))) : null,
                ], fn ($value) => $value !== null && $value !== ''),
            ], fn ($value) => $value !== null);

            $handles = match ($type) {
                WorkflowGraph::CONDITION => ['yes' => 'yes', 'no' => 'no'],
                WorkflowGraph::APPROVAL => ['approved' => 'approved', 'rejected' => 'rejected'],
                WorkflowGraph::END => [],
                default => ['next' => null],
            };

            foreach ($handles as $field => $handle) {
                $to = $target(isset($step[$field]) ? (string) $step[$field] : null);

                if ($to === 'end' && $parent !== null) {
                    continue;
                }

                if ($to !== null) {
                    $edges[] = array_filter(['id' => "{$id}-{$to}".($handle ? "-{$handle}" : ''), 'source' => $id, 'target' => $to, 'sourceHandle' => $handle], fn ($value) => $value !== null);
                }
            }
        }

        if ($first !== null) {
            array_unshift($edges, ['id' => "trigger-{$first}", 'source' => 'trigger', 'target' => $first]);
        }

        // Keep only edges that stay on one side of a loop's border, and nodes that exist.
        $ids = array_column($nodes, 'id');
        $edges = array_values(array_filter($edges, fn (array $edge) => in_array($edge['target'], $ids, true)
            && ($inside[$edge['source']] ?? null) === ($inside[$edge['target']] ?? null)));

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    private function context(?Agent $agent, ?EmailCategory $category, ?WorkflowTrigger $trigger): string
    {
        $lines = [];
        $lines[] = match (true) {
            $category !== null => "Gatilho fixo: email, do tipo {$category->value} ({$category->label()}).",
            $trigger === WorkflowTrigger::Schedule => 'Gatilho fixo: schedule (corre por agenda).',
            default => 'Gatilho por escolher. Tipos de email: '.implode('; ', array_map(fn (EmailCategory $c) => "{$c->value} ({$c->label()})", EmailCategory::cases())).'.',
        };
        $lines[] = $agent !== null ? "Agente sugerido: {$agent->key} ({$agent->name})." : 'Agente por escolher.';
        $lines[] = '';
        $lines[] = 'Agentes activos (pela chave) e as suas capacidades ([escreve] = muda dados):';

        foreach (Agent::query()->where('status', AgentStatus::Active)->orderBy('name')->with(['capabilities' => fn ($q) => $q->wherePivot('enabled', true)->orderBy('key')])->get() as $other) {
            $lines[] = "- {$other->key}: {$other->name}".($other->title ? ", {$other->title}" : '')." (nível {$other->autonomy_level->code()})";

            foreach ($other->capabilities->take(40) as $capability) {
                $lines[] = "  - {$capability->key}: {$capability->name}".($capability->is_mutating ? ' [escreve]' : '');
            }
        }

        return implode("\n", $lines);
    }
}
