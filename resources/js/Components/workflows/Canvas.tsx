import '@xyflow/react/dist/style.css';

import {
    Background,
    BackgroundVariant,
    type Connection,
    Controls,
    type Edge,
    Handle,
    MarkerType,
    type Node,
    type NodeProps,
    NodeResizer,
    type OnEdgesChange,
    type OnNodesChange,
    Position,
    ReactFlow,
    useReactFlow,
} from '@xyflow/react';
import { createContext, type DragEvent, useContext, useRef } from 'react';

import { StatusDot } from '@/Components/Status';
import { useAppearance } from '@/lib/appearance';
import { cn } from '@/lib/utils';
import {
    BLOCK_WIDTH,
    type BlockData,
    blocks,
    type BlockType,
    isLoop,
    type Readiness,
    readinessTone,
    type WfEdge,
    type WfGraph,
    type WfNode,
} from '@/lib/workflows';

/*
 * The flow as a canvas (React Flow, MIT): blocks with their ways out, loops as
 * containers, and each block's dot coloured by what will happen to it.
 */

export type FlowNode = Node<BlockData, BlockType>;

interface CanvasContextValue {
    readiness: Record<string, Readiness>;
    agentName: string | null;
    agents: { id: number; name: string }[];
    people: { id: number; name: string }[];
}

export const CanvasContext = createContext<CanvasContextValue>({ readiness: {}, agentName: null, agents: [], people: [] });

const stripe: Record<string, string> = {
    success: 'bg-status-success',
    running: 'bg-status-running',
    warning: 'bg-status-warning',
    danger: 'bg-status-danger',
    idle: 'bg-status-idle',
};

const handleClass = '!size-2.5 !border-2 !border-background !bg-muted-foreground';

function BlockNode({ id, type, data, selected }: NodeProps<FlowNode>) {
    const { readiness, agentName, agents, people } = useContext(CanvasContext);
    const meta = blocks[type];
    const Icon = meta.icon;
    const ready = readiness[id];
    const tone = ready && ready.state !== 'flow' ? readinessTone[ready.state] : 'idle';
    const who =
        type === 'agent' || type === 'condition'
            ? agentName
            : type === 'handoff'
              ? (agents.find((agent) => agent.id === data.agent_id)?.name ?? 'Escolha o agente')
              : type === 'approval' || type === 'person'
                ? (people.find((person) => person.id === data.user_id)?.name ?? 'Pessoa de recurso')
                : null;

    return (
        <div
            className={cn(
                'relative overflow-hidden rounded-xl border bg-card pr-3 pl-4 shadow-sm transition-shadow',
                type === 'condition' && 'border-violet-400/50 bg-violet-500/5',
                selected && 'ring-2 ring-primary ring-offset-2 ring-offset-background',
            )}
            style={{ width: BLOCK_WIDTH }}
        >
            <span className={cn('absolute inset-y-0 left-0 w-1', stripe[tone])} />
            {type !== 'trigger' && <Handle type="target" position={Position.Top} className={handleClass} />}
            <div className="py-2">
                <div className="flex items-center justify-between gap-2 text-[10px] font-medium tracking-wider text-muted-foreground uppercase">
                    <span className="flex min-w-0 items-center gap-1.5">
                        <span className={cn('inline-flex size-4 shrink-0 items-center justify-center rounded', meta.swatch)}>
                            <Icon className="size-2.5" />
                        </span>
                        <span className="truncate">
                            {meta.label}
                            {who ? ` · ${who}` : ''}
                        </span>
                    </span>
                    {ready && ready.state !== 'flow' && <StatusDot tone={tone} pulse={false} />}
                </div>
                <div className="mt-0.5 line-clamp-2 text-[13px] leading-snug font-medium">{data.label || meta.label}</div>
                {data.capability && <div className="mt-1 truncate font-mono text-[10.5px] text-muted-foreground">{data.capability}</div>}
            </div>
            {meta.handles ? (
                meta.handles.map((handle, index) => (
                    <Handle
                        key={handle.id}
                        id={handle.id}
                        type="source"
                        position={Position.Bottom}
                        className={handleClass}
                        style={{ left: index === 0 ? '28%' : '72%' }}
                    />
                ))
            ) : type !== 'end' ? (
                <Handle type="source" position={Position.Bottom} className={handleClass} />
            ) : null}
            {meta.handles && (
                <div className="flex justify-between px-6 pb-1 text-[10px] text-muted-foreground">
                    {meta.handles.map((handle) => (
                        <span key={handle.id}>{handle.label}</span>
                    ))}
                </div>
            )}
        </div>
    );
}

function LoopNode({ id, type, data, selected }: NodeProps<FlowNode>) {
    const { readiness } = useContext(CanvasContext);
    const meta = blocks[type];
    const Icon = meta.icon;
    const ready = readiness[id];

    return (
        <div
            className={cn(
                'size-full rounded-2xl border-[1.5px] border-dashed border-violet-400/70 bg-violet-500/[0.04]',
                selected && 'border-primary',
                ready?.state === 'missing' && 'border-status-danger/70',
            )}
        >
            <NodeResizer
                isVisible={selected}
                minWidth={BLOCK_WIDTH + 40}
                minHeight={110}
                lineClassName="!border-primary"
                handleClassName="!size-2 !bg-primary"
            />
            <Handle type="target" position={Position.Top} className={handleClass} />
            <div className="flex items-center gap-1.5 px-3 pt-2 text-[11px] font-medium text-violet-700 dark:text-violet-300">
                <Icon className="size-3.5" />
                <span className="truncate">
                    {meta.label} · {data.label}
                    {data.max ? ` (máx. ${data.max})` : ''}
                </span>
            </div>
            <Handle type="source" position={Position.Bottom} className={handleClass} />
        </div>
    );
}

const nodeTypes = {
    trigger: BlockNode,
    agent: BlockNode,
    handoff: BlockNode,
    condition: BlockNode,
    wait: BlockNode,
    approval: BlockNode,
    person: BlockNode,
    end: BlockNode,
    loop: LoopNode,
    repeat: LoopNode,
};

export function toFlow(graph: WfGraph, selected: string | null = null): { nodes: FlowNode[]; edges: Edge[] } {
    // Parents before their children, as React Flow needs.
    const ordered = [...graph.nodes].sort((a, b) => Number(Boolean(a.parentId)) - Number(Boolean(b.parentId)));

    return {
        nodes: ordered.map((node) => ({
            id: node.id,
            type: node.type,
            position: node.position ?? { x: 0, y: 0 },
            selected: node.id === selected,
            data: node.data,
            ...(node.parentId ? { parentId: node.parentId, extent: 'parent' as const } : {}),
            ...(isLoop(node.type) ? { width: node.width ?? BLOCK_WIDTH + 60, height: node.height ?? 140, style: { zIndex: 0 } } : {}),
            deletable: node.type !== 'trigger',
        })),
        edges: graph.edges.map(toFlowEdge),
    };
}

export function toFlowEdge(edge: WfEdge): Edge {
    return {
        id: edge.id,
        source: edge.source,
        target: edge.target,
        sourceHandle: edge.sourceHandle ?? null,
    };
}

export function fromFlow(nodes: FlowNode[], edges: Edge[]): WfGraph {
    return {
        nodes: nodes.map((node) => {
            const out: WfNode = {
                id: node.id,
                type: node.type as BlockType,
                position: { x: Math.round(node.position.x), y: Math.round(node.position.y) },
                data: node.data,
            };

            if (node.parentId) {
                out.parentId = node.parentId;
            }

            if (isLoop(node.type)) {
                out.width = Math.round(node.width ?? node.measured?.width ?? BLOCK_WIDTH + 60);
                out.height = Math.round(node.height ?? node.measured?.height ?? 140);
            }

            return out;
        }),
        edges: edges.map((edge) => ({
            id: edge.id,
            source: edge.source,
            target: edge.target,
            ...(edge.sourceHandle ? { sourceHandle: edge.sourceHandle } : {}),
        })),
    };
}

export function FlowCanvas({
    nodes,
    edges,
    onNodesChange,
    onEdgesChange,
    onConnect,
    onSelect,
    onDropBlock,
    readOnly = false,
}: {
    nodes: FlowNode[];
    edges: Edge[];
    onNodesChange: OnNodesChange<FlowNode>;
    onEdgesChange: OnEdgesChange;
    onConnect: (connection: Connection) => void;
    onSelect: (id: string | null) => void;
    onDropBlock: (type: BlockType, position: { x: number; y: number }, parentId: string | null) => void;
    readOnly?: boolean;
}) {
    const flow = useReactFlow<FlowNode>();
    const wrapper = useRef<HTMLDivElement>(null);
    const { resolved } = useAppearance();

    const onDrop = (event: DragEvent) => {
        event.preventDefault();
        const type = event.dataTransfer.getData('application/x-workflow-block') as BlockType;

        if (!type || !blocks[type]) {
            return;
        }

        const point = flow.screenToFlowPosition({ x: event.clientX, y: event.clientY });
        const loop = isLoop(type)
            ? undefined
            : flow.getNodes().find((node) => {
                  if (!isLoop(node.type) || node.parentId) {
                      return false;
                  }

                  const width = node.width ?? node.measured?.width ?? 0;
                  const height = node.height ?? node.measured?.height ?? 0;

                  return (
                      point.x >= node.position.x &&
                      point.x <= node.position.x + width &&
                      point.y >= node.position.y &&
                      point.y <= node.position.y + height
                  );
              });

        onDropBlock(
            type,
            loop
                ? { x: Math.max(10, point.x - loop.position.x - BLOCK_WIDTH / 2), y: Math.max(32, point.y - loop.position.y - 20) }
                : { x: point.x - BLOCK_WIDTH / 2, y: point.y - 20 },
            loop?.id ?? null,
        );
    };

    return (
        <div ref={wrapper} className="size-full">
            <ReactFlow<FlowNode>
                nodes={nodes}
                edges={edges}
                nodeTypes={nodeTypes}
                onNodesChange={onNodesChange}
                onEdgesChange={onEdgesChange}
                onConnect={onConnect}
                onSelectionChange={({ nodes: picked }) => onSelect(picked.length === 1 ? picked[0].id : null)}
                onPaneClick={() => onSelect(null)}
                onDragOver={(event) => {
                    event.preventDefault();
                    event.dataTransfer.dropEffect = 'move';
                }}
                onDrop={onDrop}
                defaultEdgeOptions={{ type: 'smoothstep', markerEnd: { type: MarkerType.ArrowClosed, width: 16, height: 16 } }}
                colorMode={resolved}
                nodesDraggable={!readOnly}
                nodesConnectable={!readOnly}
                elementsSelectable
                deleteKeyCode={readOnly ? null : ['Backspace', 'Delete']}
                onInit={(instance) => {
                    // Readable from the top, rather than the whole flow shrunk to fit. Blocks may not be
                    // measured yet, so the box comes from their positions and the usual block size.
                    const top = instance.getNodes().filter((node) => !node.parentId);
                    const box = wrapper.current?.getBoundingClientRect();

                    if (!box || top.length === 0) {
                        return;
                    }

                    const minX = Math.min(...top.map((node) => node.position.x));
                    const minY = Math.min(...top.map((node) => node.position.y));
                    const maxX = Math.max(...top.map((node) => node.position.x + (node.width ?? BLOCK_WIDTH)));
                    const width = Math.max(BLOCK_WIDTH, maxX - minX);
                    const zoom = Math.min(1, Math.max(0.7, (box.width - 48) / width));
                    instance.setViewport({ x: (box.width - width * zoom) / 2 - minX * zoom, y: 56 - minY * zoom, zoom });
                }}
                minZoom={0.3}
                panOnScroll
                zoomOnPinch
                proOptions={{ hideAttribution: true }}
                className="workflow-canvas"
            >
                <Background variant={BackgroundVariant.Dots} gap={16} size={1} />
                <Controls showInteractive={false} position="bottom-right" />
            </ReactFlow>
        </div>
    );
}

export function Palette({ onAdd, disabled = false }: { onAdd: (type: BlockType) => void; disabled?: boolean }) {
    return (
        <div className="flex flex-col gap-1.5">
            {(['agent', 'handoff', 'condition', 'loop', 'repeat', 'wait', 'approval', 'person', 'end'] as BlockType[]).map((type) => {
                const meta = blocks[type];
                const Icon = meta.icon;

                return (
                    <button
                        key={type}
                        type="button"
                        draggable={!disabled}
                        disabled={disabled}
                        title={meta.description}
                        onDragStart={(event) => {
                            event.dataTransfer.setData('application/x-workflow-block', type);
                            event.dataTransfer.effectAllowed = 'move';
                        }}
                        onClick={() => onAdd(type)}
                        className="flex cursor-grab items-center gap-2 rounded-lg border bg-card px-2 py-1.5 text-left text-[13px] font-medium transition-colors hover:bg-accent/60 active:cursor-grabbing disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span className={cn('inline-flex size-5 shrink-0 items-center justify-center rounded', meta.swatch)}>
                            <Icon className="size-3" />
                        </span>
                        {meta.label}
                    </button>
                );
            })}
            <p className="mt-1 text-[11px] leading-snug text-muted-foreground">
                Arraste para o canvas, ou clique para pôr depois do bloco escolhido.
            </p>
        </div>
    );
}
