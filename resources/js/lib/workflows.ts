import dagre from '@dagrejs/dagre';
import { Bot, CircleStop, Clock, Diamond, type LucideIcon, Repeat, Repeat1, Send, ShieldCheck, UserRound, Zap } from 'lucide-react';

import type { Tone } from '@/Components/Status';

/*
 * Fluxos de trabalho (docs/DECISOES.md): the graph shared by the canvas, the
 * list and the map, as App\Workflows\WorkflowGraph reads it on the server.
 */

export type BlockType = 'trigger' | 'agent' | 'handoff' | 'condition' | 'loop' | 'repeat' | 'wait' | 'approval' | 'person' | 'end';

export interface BlockData {
    label: string;
    instruction?: string;
    capability?: string;
    skill?: string;
    agent_id?: number;
    user_id?: number;
    items?: string;
    until?: string;
    max?: number;
    hours?: number;
    [key: string]: unknown;
}

export interface WfNode {
    id: string;
    type: BlockType;
    position: { x: number; y: number };
    parentId?: string;
    width?: number;
    height?: number;
    data: BlockData;
}

export interface WfEdge {
    id: string;
    source: string;
    target: string;
    sourceHandle?: string | null;
}

export interface WfGraph {
    nodes: WfNode[];
    edges: WfEdge[];
}

export type ReadinessState = 'alone' | 'chief' | 'person' | 'missing' | 'flow';

export interface Readiness {
    state: ReadinessState;
    reasons: string[];
    fixes: string[];
    agent: string | null;
}

export interface ReadinessSummary {
    alone: number;
    chief: number;
    person: number;
    missing: number;
}

export const readinessLabel: Record<ReadinessState, string> = {
    alone: 'Executa sozinho',
    chief: 'Revalida o Chief of Staff',
    person: 'Decide uma pessoa',
    missing: 'Falta capacidade',
    flow: 'Fluxo',
};

export const readinessShort: Record<ReadinessState, string> = {
    alone: 'sozinho',
    chief: 'Chief of Staff',
    person: 'pessoa',
    missing: 'falta',
    flow: 'fluxo',
};

export const readinessTone: Record<ReadinessState, Tone> = {
    alone: 'success',
    chief: 'running',
    person: 'warning',
    missing: 'danger',
    flow: 'idle',
};

export const fixLabel: Record<string, string> = {
    give_capability: 'Dar a capacidade ao agente (ficha do agente)',
    attach_skill: 'Associar a skill ao agente',
    raise_level: 'Subir o nível de confiança do agente',
    ask_rethink: 'Pedir a capacidade à Rethink',
    check_integration: 'Ver a integração em Definições',
    choose_agent: 'Escolher o agente',
    activate_agent: 'Reactivar o agente',
};

export interface BlockMeta {
    label: string;
    icon: LucideIcon;
    description: string;
    /** Tailwind classes for the small square icon. */
    swatch: string;
    /** The ways out, when there is more than one. */
    handles?: { id: string; label: string }[];
}

export const blocks: Record<BlockType, BlockMeta> = {
    trigger: { label: 'Gatilho', icon: Zap, description: 'O email chega, já classificado pela triagem.', swatch: 'bg-status-idle text-white' },
    agent: {
        label: 'Passo do agente',
        icon: Bot,
        description: 'O agente do fluxo faz um passo, com uma capacidade.',
        swatch: 'bg-primary text-primary-foreground',
    },
    handoff: {
        label: 'Passar a agente',
        icon: Send,
        description: 'Outro agente faz o passo numa sub-tarefa.',
        swatch: 'bg-status-running text-white',
    },
    condition: {
        label: 'Condição',
        icon: Diamond,
        description: 'Uma pergunta de sim ou não decide o caminho.',
        swatch: 'bg-violet-500 text-white',
        handles: [
            { id: 'yes', label: 'Sim' },
            { id: 'no', label: 'Não' },
        ],
    },
    loop: { label: 'Ciclo para cada', icon: Repeat, description: 'Repete os blocos de dentro para cada item.', swatch: 'bg-violet-500 text-white' },
    repeat: {
        label: 'Repetir até',
        icon: Repeat1,
        description: 'Repete os blocos de dentro até uma resposta sim.',
        swatch: 'bg-violet-500 text-white',
    },
    wait: { label: 'Esperar', icon: Clock, description: 'O fluxo pára umas horas e continua.', swatch: 'bg-status-idle text-white' },
    approval: {
        label: 'Aprovação',
        icon: ShieldCheck,
        description: 'Uma pessoa aprova ou rejeita.',
        swatch: 'bg-status-warning text-white',
        handles: [
            { id: 'approved', label: 'Aprovado' },
            { id: 'rejected', label: 'Rejeitado' },
        ],
    },
    person: {
        label: 'Tarefa a pessoa',
        icon: UserRound,
        description: 'Uma pessoa faz o passo e marca-o feito.',
        swatch: 'bg-status-warning text-white',
    },
    end: { label: 'Fim', icon: CircleStop, description: 'O fluxo acaba e a tarefa vai para revisão.', swatch: 'bg-foreground text-background' },
};

export const paletteOrder: BlockType[] = ['agent', 'handoff', 'condition', 'loop', 'repeat', 'wait', 'approval', 'person', 'end'];

export const isLoop = (type: string | undefined): boolean => type === 'loop' || type === 'repeat';

export const handleLabel = (handle: string | null | undefined): string | null =>
    ({ yes: 'Sim', no: 'Não', approved: 'Aprovado', rejected: 'Rejeitado' })[handle ?? ''] ?? null;

export const BLOCK_WIDTH = 240;
export const BLOCK_HEIGHT = 58;

/** One row of the list view: the block, how deep it sits, and the branch it opens. */
export interface FlowRow {
    node: WfNode;
    depth: number;
    /** "Se sim", "Para cada item"…: shown above the first row of a branch. */
    branch?: string;
}

/**
 * The graph as an indented list: branches of a condition one after the other
 * until they meet again, the blocks of a loop under it.
 */
export function flatten(graph: WfGraph): FlowRow[] {
    const byId = new Map(graph.nodes.map((node) => [node.id, node]));
    const next = (id: string, handle: string | null = null) =>
        graph.edges.find((edge) => edge.source === id && (edge.sourceHandle ?? null) === handle && byId.has(edge.target))?.target ?? null;
    const rows: FlowRow[] = [];
    const seen = new Set<string>();

    const reachable = (start: string | null): string[] => {
        const order: string[] = [];
        const queue = start ? [start] : [];

        while (queue.length > 0) {
            const id = queue.shift()!;

            if (order.includes(id)) {
                continue;
            }

            order.push(id);
            graph.edges.filter((edge) => edge.source === id).forEach((edge) => queue.push(edge.target));
        }

        return order;
    };

    const walk = (start: string | null, depth: number, stop: string | null, branch?: string) => {
        let id = start;
        let label = branch;

        while (id && id !== stop && !seen.has(id)) {
            const node = byId.get(id);

            if (!node) {
                break;
            }

            seen.add(id);
            rows.push({ node, depth, branch: label });
            label = undefined;

            const meta = blocks[node.type];

            if (isLoop(node.type)) {
                const children = graph.nodes.filter((child) => child.parentId === node.id);
                const targets = new Set(graph.edges.filter((edge) => children.some((child) => child.id === edge.source)).map((edge) => edge.target));
                const first = children.find((child) => !targets.has(child.id)) ?? children[0];

                walk(first?.id ?? null, depth + 1, null, node.type === 'loop' ? 'Para cada item' : 'Em cada volta');
                id = next(node.id);
            } else if (meta.handles) {
                const [a, b] = meta.handles;
                const left = next(node.id, a.id);
                const right = next(node.id, b.id);
                // Where the two branches meet again: the first block both reach.
                const fromRight = new Set(reachable(right));
                const join = reachable(left).find((candidate) => fromRight.has(candidate)) ?? null;

                walk(left === join ? null : left, depth + 1, join, `Se ${a.label.toLowerCase()}`);
                walk(right === join ? null : right, depth + 1, join, `Se ${b.label.toLowerCase()}`);
                id = join;
            } else {
                id = next(node.id);
            }
        }
    };

    const trigger = graph.nodes.find((node) => node.type === 'trigger');
    walk(trigger?.id ?? null, 0, null);

    // Blocks nobody reaches yet still show, at the end, so nothing gets lost in the list.
    graph.nodes
        .filter((node) => !seen.has(node.id) && !node.parentId)
        .forEach((node) =>
            rows.push({ node, depth: 0, branch: seen.size > 0 && !rows.some((row) => row.branch === 'Sem ligação') ? 'Sem ligação' : undefined }),
        );

    return rows;
}

/**
 * Lays the blocks out top to bottom (dagre), the blocks of each loop inside it.
 */
export function layout(graph: WfGraph): WfGraph {
    const sizes = new Map<string, { width: number; height: number }>();
    const positions = new Map<string, { x: number; y: number }>();

    const place = (members: WfNode[], padTop = 0, pad = 0) => {
        const g = new dagre.graphlib.Graph();
        g.setGraph({ rankdir: 'TB', nodesep: 40, ranksep: 46, marginx: pad, marginy: pad });
        g.setDefaultEdgeLabel(() => ({}));
        const ids = new Set(members.map((node) => node.id));

        members.forEach((node) => {
            const size = sizes.get(node.id) ?? { width: BLOCK_WIDTH, height: BLOCK_HEIGHT };
            g.setNode(node.id, size);
        });
        graph.edges.filter((edge) => ids.has(edge.source) && ids.has(edge.target)).forEach((edge) => g.setEdge(edge.source, edge.target));
        dagre.layout(g);

        let width = 0;
        let height = 0;
        members.forEach((node) => {
            const point = g.node(node.id);
            const size = sizes.get(node.id) ?? { width: BLOCK_WIDTH, height: BLOCK_HEIGHT };
            const x = point.x - size.width / 2;
            const y = point.y - size.height / 2 + padTop;
            positions.set(node.id, { x, y });
            width = Math.max(width, x + size.width + pad);
            height = Math.max(height, y + size.height + pad);
        });

        return { width, height };
    };

    graph.nodes
        .filter((node) => isLoop(node.type))
        .forEach((loop) => {
            const children = graph.nodes.filter((child) => child.parentId === loop.id);
            const box = children.length > 0 ? place(children, 30, 20) : { width: BLOCK_WIDTH + 40, height: 110 };
            sizes.set(loop.id, { width: Math.max(box.width, BLOCK_WIDTH + 40), height: Math.max(box.height, 110) });
        });

    place(graph.nodes.filter((node) => !node.parentId));

    return {
        ...graph,
        nodes: graph.nodes.map((node) => ({
            ...node,
            position: positions.get(node.id) ?? node.position,
            ...(isLoop(node.type) ? sizes.get(node.id) : {}),
        })),
    };
}

/** True when every block sits at the origin (a fresh proposal from the assistant). */
export const needsLayout = (graph: WfGraph): boolean =>
    graph.nodes.length > 1 && graph.nodes.every((node) => !node.position || (node.position.x === 0 && node.position.y === 0));

export function newId(type: BlockType, graph: WfGraph): string {
    let n = graph.nodes.length + 1;

    while (graph.nodes.some((node) => node.id === `${type}_${n}`)) {
        n++;
    }

    return `${type}_${n}`;
}

export function defaultData(type: BlockType): BlockData {
    switch (type) {
        case 'condition':
            return { label: 'Nova pergunta?' };
        case 'loop':
            return { label: 'Para cada item', items: '', max: 5 };
        case 'repeat':
            return { label: 'Repetir até', until: '', max: 3 };
        case 'wait':
            return { label: 'Esperar', hours: 24 };
        case 'approval':
            return { label: 'Aprovar' };
        case 'end':
            return { label: 'Fim' };
        default:
            return { label: blocks[type].label };
    }
}

/**
 * Adds a block after another one, in the same loop, taking over the way out
 * the first one had (the list's "Adicionar passo" and the palette's click).
 */
export function insertAfter(graph: WfGraph, afterId: string | null, type: BlockType, way: string | null = null): { graph: WfGraph; id: string } {
    const after = graph.nodes.find((node) => node.id === afterId) ?? graph.nodes.find((node) => node.type === 'trigger');
    // After a condition or an approval, the first way out that is still free (or the first one).
    const ways = after ? blocks[after.type].handles : undefined;
    const handle =
        way === null && ways
            ? (ways.find((option) => !graph.edges.some((edge) => edge.source === after!.id && edge.sourceHandle === option.id)) ?? ways[0]).id
            : way;
    const id = newId(type, graph);
    const inside = after && isLoop(after.type) && handle === 'body' ? after.id : after?.parentId;
    const node: WfNode = {
        id,
        type,
        position: after
            ? {
                  x: after.position.x,
                  y: after.position.y + (isLoop(after.type) && handle !== 'body' ? (after.height ?? 150) + 40 : BLOCK_HEIGHT + 50),
              }
            : { x: 0, y: 0 },
        ...(inside ? { parentId: inside } : {}),
        ...(isLoop(type) ? { width: BLOCK_WIDTH + 60, height: 140 } : {}),
        data: defaultData(type),
    };

    if (handle === 'body' && after) {
        node.position = { x: 20, y: 40 + graph.nodes.filter((child) => child.parentId === after.id).length * (BLOCK_HEIGHT + 30) };
    }

    if (handle === 'body' && after) {
        // The loop grows to hold the new block.
        const nodes = graph.nodes.map((other) =>
            other.id === after.id
                ? {
                      ...other,
                      width: Math.max(other.width ?? 0, BLOCK_WIDTH + 40),
                      height: Math.max(other.height ?? 0, node.position.y + BLOCK_HEIGHT + 50),
                  }
                : other,
        );

        return { graph: { ...graph, nodes: [...nodes, node] }, id };
    }

    if (!after) {
        return { graph: { ...graph, nodes: [...graph.nodes, node] }, id };
    }

    // Make room: what sits below, on the same level, moves down by one block.
    const gap = (isLoop(type) ? 140 : BLOCK_HEIGHT) + 50;
    const nodes = graph.nodes.map((other) =>
        (other.parentId ?? null) === (node.parentId ?? null) && other.id !== after.id && other.position.y >= node.position.y - 10
            ? { ...other, position: { ...other.position, y: other.position.y + gap } }
            : other,
    );
    const out = graph.edges.find((edge) => edge.source === after.id && (edge.sourceHandle ?? null) === handle);
    const edges = graph.edges.filter((edge) => edge !== out);
    edges.push({ id: `${after.id}-${id}`, source: after.id, target: id, ...(handle ? { sourceHandle: handle } : {}) });

    if (out && type !== 'end') {
        const way = blocks[type].handles?.[0]?.id ?? null;
        edges.push({ id: `${id}-${out.target}`, source: id, target: out.target, ...(way ? { sourceHandle: way } : {}) });
    }

    return { graph: { nodes: [...nodes, node], edges }, id };
}

/**
 * Removes a block (and what is inside a loop), joining what came before to
 * what came after when that is unambiguous.
 */
export function removeNode(graph: WfGraph, id: string): WfGraph {
    const gone = new Set([id, ...graph.nodes.filter((node) => node.parentId === id).map((node) => node.id)]);
    const incoming = graph.edges.filter((edge) => edge.target === id);
    const outgoing = graph.edges.filter((edge) => edge.source === id);
    const edges = graph.edges.filter((edge) => !gone.has(edge.source) && !gone.has(edge.target));

    if (outgoing.length === 1) {
        incoming.forEach((edge) => edges.push({ ...edge, id: `${edge.source}-${outgoing[0].target}`, target: outgoing[0].target }));
    }

    return { nodes: graph.nodes.filter((node) => !gone.has(node.id)), edges };
}

/** Why a new connection is not allowed, or null. */
export function connectionProblem(graph: WfGraph, source: string, target: string): string | null {
    const from = graph.nodes.find((node) => node.id === source);
    const to = graph.nodes.find((node) => node.id === target);

    if (!from || !to || source === target) {
        return 'Ligação inválida.';
    }

    if (to.type === 'trigger') {
        return 'Nada pode ligar ao gatilho.';
    }

    if ((from.parentId ?? null) !== (to.parentId ?? null)) {
        return 'As ligações não atravessam a fronteira de um ciclo: ponha os dois blocos do mesmo lado.';
    }

    const reaches = (start: string, goal: string, seen = new Set<string>()): boolean =>
        start === goal ||
        (!seen.has(start) && (seen.add(start), graph.edges.filter((edge) => edge.source === start).some((edge) => reaches(edge.target, goal, seen))));

    if (reaches(target, source)) {
        return 'O fluxo não pode voltar para trás sozinho: para repetir passos, use um bloco de ciclo.';
    }

    return null;
}
