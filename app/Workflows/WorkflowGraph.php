<?php

namespace App\Workflows;

/**
 * Reads a flow's graph, the same one the canvas and the list edit
 * (docs/DECISOES.md, "Fluxos de trabalho").
 *
 * A node is {id, type, position, parentId?, data}; its settings live in data.
 * An edge is {id, source, target, sourceHandle?}: conditions leave by "yes" or
 * "no", approvals by "approved" or "rejected", everything else by no handle.
 * The blocks inside a loop have the loop as parentId; the body starts at the
 * child nobody inside points to and an iteration ends where a path inside
 * stops.
 */
final class WorkflowGraph
{
    public const TRIGGER = 'trigger';

    public const AGENT = 'agent';

    public const HANDOFF = 'handoff';

    public const CONDITION = 'condition';

    public const LOOP = 'loop';

    public const REPEAT = 'repeat';

    public const WAIT = 'wait';

    public const APPROVAL = 'approval';

    public const PERSON = 'person';

    public const END = 'end';

    public const TYPES = [self::TRIGGER, self::AGENT, self::HANDOFF, self::CONDITION, self::LOOP, self::REPEAT, self::WAIT, self::APPROVAL, self::PERSON, self::END];

    /** The ways out of each type that has more than one. */
    public const HANDLES = [
        self::CONDITION => ['yes', 'no'],
        self::APPROVAL => ['approved', 'rejected'],
    ];

    /** Most items or rounds a loop may go through. */
    public const MAX_ITERATIONS = 20;

    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /** @var list<array<string, mixed>> */
    private array $edges;

    /**
     * @param  array<string, mixed>|null  $graph
     */
    public function __construct(?array $graph)
    {
        foreach ((array) ($graph['nodes'] ?? []) as $node) {
            if (is_array($node) && isset($node['id'])) {
                $this->nodes[(string) $node['id']] = $node;
            }
        }

        $this->edges = array_values(array_filter((array) ($graph['edges'] ?? []), 'is_array'));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function edges(): array
    {
        return $this->edges;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function node(?string $id): ?array
    {
        return $id === null ? null : ($this->nodes[$id] ?? null);
    }

    public function type(string $id): ?string
    {
        return $this->nodes[$id]['type'] ?? null;
    }

    /**
     * A setting of the node, from its data.
     */
    public function data(string $id, string $key, mixed $default = null): mixed
    {
        $value = $this->nodes[$id]['data'][$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public function label(string $id): string
    {
        return trim((string) $this->data($id, 'label', '')) ?: 'Passo sem nome';
    }

    public function parent(string $id): ?string
    {
        $parent = $this->nodes[$id]['parentId'] ?? null;

        return $parent !== null && isset($this->nodes[$parent]) ? (string) $parent : null;
    }

    public function trigger(): ?string
    {
        foreach ($this->nodes as $id => $node) {
            if (($node['type'] ?? null) === self::TRIGGER) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Where the flow goes from a node, by the given way out.
     */
    public function next(string $id, ?string $handle = null): ?string
    {
        foreach ($this->edges as $edge) {
            if (($edge['source'] ?? null) === $id && ($edge['sourceHandle'] ?? null) === $handle && isset($this->nodes[$edge['target'] ?? ''])) {
                return (string) $edge['target'];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function children(string $loop): array
    {
        return array_map('strval', array_keys(array_filter($this->nodes, fn (array $node) => ($node['parentId'] ?? null) === $loop)));
    }

    /**
     * The first block of a loop's body: the child no other child points to.
     */
    public function bodyStart(string $loop): ?string
    {
        $children = $this->children($loop);
        $targets = array_map(fn (array $edge) => $edge['target'] ?? null, array_filter($this->edges, fn (array $edge) => in_array($edge['source'] ?? null, $children, true)));

        foreach ($children as $child) {
            if (! in_array($child, $targets, true)) {
                return $child;
            }
        }

        return $children[0] ?? null;
    }

    /**
     * Blocks that do work or need someone (not the trigger, loops, waits or the end).
     *
     * @return list<string>
     */
    public function workNodes(): array
    {
        return array_map('strval', array_keys(array_filter($this->nodes, fn (array $node) => in_array($node['type'] ?? null, [self::AGENT, self::HANDOFF, self::CONDITION, self::APPROVAL, self::PERSON], true))));
    }

    /**
     * What is wrong with the graph, in words for the person drawing it.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];
        $triggers = array_filter($this->nodes, fn (array $node) => ($node['type'] ?? null) === self::TRIGGER);

        if (count($triggers) !== 1) {
            $problems[] = 'O fluxo precisa de exactamente um gatilho.';
        }

        foreach ($this->nodes as $id => $node) {
            $type = $node['type'] ?? null;

            if (! in_array($type, self::TYPES, true)) {
                $problems[] = "O bloco «{$this->label((string) $id)}» é de um tipo desconhecido.";

                continue;
            }

            $parent = $node['parentId'] ?? null;

            if ($parent !== null && ! in_array($this->type((string) $parent), [self::LOOP, self::REPEAT], true)) {
                $problems[] = "O bloco «{$this->label((string) $id)}» está dentro de algo que não é um ciclo.";
            }

            if ($parent !== null && in_array($type, [self::TRIGGER, self::LOOP, self::REPEAT], true)) {
                $problems[] = $type === self::TRIGGER ? 'O gatilho não pode ficar dentro de um ciclo.' : 'Um ciclo não pode ficar dentro de outro ciclo.';
            }

            if (in_array($type, [self::LOOP, self::REPEAT], true) && $this->children((string) $id) === []) {
                $problems[] = "O ciclo «{$this->label((string) $id)}» não tem nenhum bloco dentro.";
            }
        }

        $seen = [];

        foreach ($this->edges as $edge) {
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            $handle = $edge['sourceHandle'] ?? null;

            if (! isset($this->nodes[$source], $this->nodes[$target])) {
                $problems[] = 'Há uma ligação para um bloco que já não existe.';

                continue;
            }

            if (($this->nodes[$source]['parentId'] ?? null) !== ($this->nodes[$target]['parentId'] ?? null)) {
                $problems[] = "A ligação de «{$this->label($source)}» a «{$this->label($target)}» atravessa a fronteira de um ciclo.";
            }

            $allowed = self::HANDLES[$this->type($source)] ?? [null];

            if (! in_array($handle, $allowed, true)) {
                $problems[] = "«{$this->label($source)}» tem uma saída que não existe.";
            }

            if ($this->type($target) === self::TRIGGER) {
                $problems[] = 'Nada pode ligar ao gatilho.';
            }

            $key = $source.'|'.($handle ?? '');

            if (isset($seen[$key])) {
                $problems[] = "«{$this->label($source)}» tem mais do que uma ligação na mesma saída.";
            }

            $seen[$key] = true;
        }

        if ($this->hasCycle()) {
            $problems[] = 'O fluxo volta para trás sozinho. Para repetir passos, use um bloco de ciclo.';
        }

        return array_values(array_unique($problems));
    }

    private function hasCycle(): bool
    {
        $state = [];
        $visit = function (string $id) use (&$visit, &$state): bool {
            $state[$id] = 1;

            foreach ($this->edges as $edge) {
                if (($edge['source'] ?? null) !== $id) {
                    continue;
                }

                $target = (string) ($edge['target'] ?? '');

                if (($state[$target] ?? 0) === 1 || (($state[$target] ?? 0) === 0 && isset($this->nodes[$target]) && $visit($target))) {
                    return true;
                }
            }

            $state[$id] = 2;

            return false;
        };

        foreach (array_keys($this->nodes) as $id) {
            if (($state[$id] ?? 0) === 0 && $visit((string) $id)) {
                return true;
            }
        }

        return false;
    }
}
