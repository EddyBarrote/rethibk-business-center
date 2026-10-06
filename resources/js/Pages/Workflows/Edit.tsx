import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    addEdge,
    applyEdgeChanges,
    applyNodeChanges,
    type Connection,
    type Edge,
    type EdgeChange,
    type NodeChange,
    ReactFlowProvider,
} from '@xyflow/react';
import { LayoutGrid, List, Pause, Play, Plus, Sparkles, Trash2, Waypoints } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import { FlowCanvas, type FlowNode, fromFlow, Palette, toFlow, toFlowEdge } from '@/Components/workflows/Canvas';
import { CanvasContext } from '@/Components/workflows/Canvas';
import { type AgentOption, BlockInspector, type CapabilityOption } from '@/Components/workflows/Inspector';
import { FlowSteps, ReadinessBadge, ReadinessLegend } from '@/Components/workflows/Readiness';
import AppLayout from '@/Layouts/AppLayout';
import { postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
    blocks,
    type BlockData,
    type BlockType,
    connectionProblem,
    defaultData,
    flatten,
    insertAfter,
    isLoop,
    layout,
    needsLayout,
    newId,
    paletteOrder,
    type Readiness,
    type ReadinessSummary,
    removeNode,
    type WfGraph,
} from '@/lib/workflows';
import type { Option } from '@/types';

interface Props {
    workflow: { id: number; name: string; status: 'draft' | 'active' | 'paused'; status_label: string; problems: string[] } | null;
    initial: {
        name: string;
        description: string;
        email_category: string | null;
        agent_id: number | null;
        fallback_user_id: number | null;
        graph: WfGraph;
    };
    readiness: Record<string, Readiness>;
    categories: Option[];
    agents: AgentOption[];
    people: { id: number; name: string }[];
    capabilities: CapabilityOption[];
    skills: { key: string; name: string }[];
    can_draft: boolean;
}

const statusTone = (status: string): Tone => (({ active: 'success', draft: 'idle', paused: 'warning' }) as Record<string, Tone>)[status] ?? 'idle';

const summarise = (readiness: Record<string, Readiness>): ReadinessSummary => {
    const counts = { alone: 0, chief: 0, person: 0, missing: 0 };
    Object.values(readiness).forEach((row) => {
        if (row.state in counts) {
            counts[row.state as keyof ReadinessSummary]++;
        }
    });

    return counts;
};

export default function WorkflowEdit(props: Props) {
    return (
        <ReactFlowProvider>
            <Editor {...props} />
        </ReactFlowProvider>
    );
}

function Editor({ workflow, initial, readiness: initialReadiness, categories, agents, people, capabilities, skills, can_draft }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const linked = new URLSearchParams(window.location.search).get('node');
    const start = useMemo(() => toFlow(needsLayout(initial.graph) ? layout(initial.graph) : initial.graph, linked), [initial.graph]); // eslint-disable-line react-hooks/exhaustive-deps
    const [nodes, setNodes] = useState<FlowNode[]>(start.nodes);
    const [edges, setEdges] = useState<Edge[]>(start.edges);
    const [form, setForm] = useState({
        name: initial.name,
        description: initial.description,
        email_category: initial.email_category ?? '',
        agent_id: initial.agent_id ? String(initial.agent_id) : '',
        fallback_user_id: initial.fallback_user_id ? String(initial.fallback_user_id) : '',
    });
    const [view, setView] = useState<'canvas' | 'list'>('canvas');
    const [selected, setSelected] = useState<string | null>(linked);
    const [readiness, setReadiness] = useState(initialReadiness);
    const [problems, setProblems] = useState<string[]>(workflow?.problems ?? []);
    const [saving, setSaving] = useState(false);
    const [dirty, setDirty] = useState(false);
    const [drafting, setDrafting] = useState({ open: false, text: '', busy: false });
    const [confirmDelete, setConfirmDelete] = useState(false);

    const graph = useMemo(() => fromFlow(nodes, edges), [nodes, edges]);
    const agent = agents.find((row) => String(row.id) === form.agent_id);
    const summary = summarise(readiness);
    const selectedNode = graph.nodes.find((node) => node.id === selected);

    // What decides readiness: blocks, settings and ways out, not positions.
    const structure = JSON.stringify([
        form.agent_id,
        graph.nodes.map((node) => [node.id, node.type, node.parentId, node.data]),
        graph.edges.map((edge) => [edge.source, edge.target, edge.sourceHandle]),
    ]);
    // As saved: the server already sent its readiness, and nothing is pending.
    const saved = useRef(structure);

    useEffect(() => {
        if (structure === saved.current) {
            return;
        }

        setDirty(true);
        const timer = window.setTimeout(() => {
            postJson<{ readiness: Record<string, Readiness>; problems: string[] }>('/workflows/check', {
                agent_id: form.agent_id ? Number(form.agent_id) : null,
                graph,
            })
                .then((result) => {
                    setReadiness(result.readiness);
                    setProblems(result.problems);
                })
                .catch(() => undefined);
        }, 400);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [structure]);

    useEffect(() => {
        const warn = (event: BeforeUnloadEvent) => {
            if (dirty) {
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [dirty]);

    const setGraph = useCallback((next: WfGraph, select: string | null = null) => {
        const flow = toFlow(next, select);
        setNodes(flow.nodes);
        setEdges(flow.edges);
    }, []);

    const onNodesChange = useCallback((changes: NodeChange<FlowNode>[]) => {
        setNodes((current) => applyNodeChanges(changes, current));

        if (changes.some((change) => change.type === 'position' && !change.dragging)) {
            setDirty(true);
        }
    }, []);

    const onEdgesChange = useCallback((changes: EdgeChange[]) => setEdges((current) => applyEdgeChanges(changes, current)), []);

    const onConnect = useCallback(
        (connection: Connection) => {
            const problem = connectionProblem(graph, connection.source, connection.target);

            if (problem) {
                toast.error(problem);

                return;
            }

            setEdges((current) =>
                addEdge(
                    toFlowEdge({
                        id: `${connection.source}-${connection.target}-${connection.sourceHandle ?? 'out'}`,
                        source: connection.source,
                        target: connection.target,
                        sourceHandle: connection.sourceHandle,
                    }),
                    // One connection per way out: a new one replaces the old.
                    current.filter(
                        (edge) => !(edge.source === connection.source && (edge.sourceHandle ?? null) === (connection.sourceHandle ?? null)),
                    ),
                ),
            );
        },
        [graph],
    );

    const add = (type: BlockType) => {
        const result = insertAfter(graph, selected, type);
        setGraph(result.graph, result.id);
        setSelected(result.id);
    };

    const addInside = (loop: string, type: BlockType) => {
        const result = insertAfter(graph, loop, type, 'body');
        setGraph(result.graph, result.id);
        setSelected(result.id);
    };

    const drop = (type: BlockType, position: { x: number; y: number }, parentId: string | null) => {
        const id = newId(type, graph);
        setGraph({
            ...graph,
            nodes: [
                ...graph.nodes,
                {
                    id,
                    type,
                    position,
                    ...(parentId ? { parentId } : {}),
                    ...(isLoop(type) ? { width: 300, height: 140 } : {}),
                    data: defaultData(type),
                },
            ],
        });
        setSelected(id);
    };

    const patch = (id: string, data: Partial<BlockData>) => {
        setNodes((current) => current.map((node) => (node.id === id ? { ...node, data: { ...node.data, ...data } } : node)));
    };

    const remove = (id: string) => {
        setGraph(removeNode(graph, id));
        setSelected(null);
    };

    const save = (activate = false) => {
        setSaving(true);
        const payload: RequestPayload = {
            ...form,
            agent_id: form.agent_id ? Number(form.agent_id) : null,
            fallback_user_id: form.fallback_user_id ? Number(form.fallback_user_id) : null,
            email_category: form.email_category || null,
            // The graph travels as JSON; Inertia's payload type does not know its shape.
            graph: graph as unknown as Record<string, never>,
            activate,
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => {
                saved.current = structure;
                setDirty(false);
            },
            onError: (errs: Record<string, string>) => toast.error(Object.values(errs)[0] ?? 'Não foi possível guardar.'),
            onFinish: () => setSaving(false),
        };

        if (workflow) {
            router.put(`/workflows/${workflow.id}`, payload, options);
        } else {
            router.post('/workflows', payload, options);
        }
    };

    const setStatus = (status: 'active' | 'paused') => {
        if (!workflow) {
            return;
        }

        if (status === 'active' && dirty) {
            save(true);

            return;
        }

        router.put(`/workflows/${workflow.id}/status`, { status }, { preserveScroll: true });
    };

    const propose = () => {
        if (!form.agent_id) {
            toast.error('Escolha primeiro o agente que trata o fluxo.');

            return;
        }

        setDrafting((current) => ({ ...current, busy: true }));
        postJson<{ graph: WfGraph }>('/workflows/draft', {
            description: drafting.text,
            agent_id: Number(form.agent_id),
            email_category: form.email_category || null,
        })
            .then((result) => {
                setGraph(layout(result.graph));
                setSelected(null);
                setDrafting({ open: false, text: drafting.text, busy: false });
                toast.success('Passos propostos. Reveja-os antes de guardar.');
            })
            .catch((error: Error) => {
                toast.error(error.message);
                setDrafting((current) => ({ ...current, busy: false }));
            });
    };

    const title = workflow?.name ?? (form.name || 'Novo fluxo');

    return (
        <AppLayout breadcrumbs={[{ label: 'Fluxos de trabalho', href: '/workflows' }, { label: workflow ? workflow.name : 'Novo fluxo' }]}>
            <Head title={title} />

            <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
                <div className="min-w-0 space-y-1">
                    <div className="flex flex-wrap items-center gap-2">
                        <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
                        {workflow && <StatusBadge tone={statusTone(workflow.status)}>{workflow.status_label}</StatusBadge>}
                        {dirty && <span className="text-xs text-muted-foreground">Alterações por guardar</span>}
                    </div>
                    <p className="text-sm text-muted-foreground">
                        O que acontece a um tipo de email depois da triagem. Cada passo é verificado contra o que o agente tem.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <div className="inline-flex rounded-lg border p-0.5" role="tablist" aria-label="Vista">
                        {(
                            [
                                ['canvas', 'Canvas', Waypoints],
                                ['list', 'Lista', List],
                            ] as const
                        ).map(([value, label, Icon]) => (
                            <button
                                key={value}
                                type="button"
                                role="tab"
                                aria-selected={view === value}
                                onClick={() => setView(value)}
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-sm',
                                    view === value ? 'bg-secondary font-medium' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <Icon className="size-3.5" />
                                {label}
                            </button>
                        ))}
                    </div>
                    {can_draft && (
                        <Button variant="outline" onClick={() => setDrafting((current) => ({ ...current, open: true }))}>
                            <Sparkles />
                            Propor passos
                        </Button>
                    )}
                    {workflow?.status === 'active' ? (
                        <Button variant="outline" onClick={() => setStatus('paused')}>
                            <Pause />
                            Pausar
                        </Button>
                    ) : (
                        <Button variant="outline" disabled={saving} onClick={() => (workflow ? setStatus('active') : save(true))}>
                            <Play />
                            Activar
                        </Button>
                    )}
                    <Button disabled={saving} onClick={() => save(false)}>
                        Guardar
                    </Button>
                </div>
            </div>

            <div className="grid gap-4 rounded-xl border bg-card p-4 sm:grid-cols-2 xl:grid-cols-4">
                <Field id="name" label="Nome do fluxo" error={errors.name}>
                    <Input id="name" value={form.name} maxLength={200} onChange={(event) => setForm({ ...form, name: event.target.value })} />
                </Field>
                <Field id="email_category" label="Tipo de email que o inicia" error={errors.email_category}>
                    <NativeSelect
                        id="email_category"
                        value={form.email_category}
                        onChange={(event) => setForm({ ...form, email_category: event.target.value })}
                    >
                        <option value="">Escolha o tipo</option>
                        {categories.map((category) => (
                            <option key={category.value} value={category.value}>
                                {category.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="agent_id" label="Agente que trata" error={errors.agent_id}>
                    <NativeSelect id="agent_id" value={form.agent_id} onChange={(event) => setForm({ ...form, agent_id: event.target.value })}>
                        <option value="">Escolha o agente</option>
                        {agents.map((row) => (
                            <option key={row.id} value={row.id}>
                                {row.name} · {row.level}
                                {row.active ? '' : ' (suspenso)'}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="fallback_user_id" label="Se o agente não conseguir, passa a" error={errors.fallback_user_id}>
                    <NativeSelect
                        id="fallback_user_id"
                        value={form.fallback_user_id}
                        onChange={(event) => setForm({ ...form, fallback_user_id: event.target.value })}
                    >
                        <option value="">A chefia do agente</option>
                        {people.map((person) => (
                            <option key={person.id} value={person.id}>
                                {person.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="description" label="Como a Triagem reconhece este tipo de email (opcional)" className="sm:col-span-2 xl:col-span-4">
                    <Input
                        id="description"
                        value={form.description}
                        maxLength={4000}
                        onChange={(event) => setForm({ ...form, description: event.target.value })}
                    />
                </Field>
            </div>

            <div className={cn('grid gap-4', view === 'canvas' ? 'lg:grid-cols-[10rem_minmax(0,1fr)_19rem]' : 'lg:grid-cols-[minmax(0,1fr)_19rem]')}>
                {view === 'canvas' && (
                    <div className="hidden lg:block">
                        <h2 className="mb-2 text-xs font-medium tracking-widest text-muted-foreground uppercase">Blocos</h2>
                        <Palette onAdd={add} />
                    </div>
                )}

                <div className="min-w-0">
                    {view === 'canvas' ? (
                        <div className="relative h-[calc(100dvh-20rem)] min-h-[34rem] overflow-hidden rounded-xl border bg-background">
                            <CanvasContext.Provider value={{ readiness, agentName: agent?.name ?? null, agents, people }}>
                                <FlowCanvas
                                    nodes={nodes}
                                    edges={edges}
                                    onNodesChange={onNodesChange}
                                    onEdgesChange={onEdgesChange}
                                    onConnect={onConnect}
                                    onSelect={setSelected}
                                    onDropBlock={drop}
                                />
                            </CanvasContext.Provider>
                            <div className="pointer-events-none absolute bottom-3 left-3 flex flex-wrap gap-2">
                                <ReadinessLegend className="pointer-events-auto rounded-lg border bg-card/90 px-2.5 py-1" />
                            </div>
                            <Button variant="outline" size="sm" className="absolute top-3 right-3" onClick={() => setGraph(layout(graph))}>
                                <LayoutGrid />
                                Organizar
                            </Button>
                        </div>
                    ) : (
                        <div className="overflow-hidden rounded-xl border bg-card">
                            <FlowSteps
                                rows={flatten(graph)}
                                readiness={readiness}
                                selected={selected}
                                onSelect={setSelected}
                                agents={agents}
                                people={people}
                                actions={(row) =>
                                    row.node.type === 'end' ? null : (
                                        <AddMenu
                                            node={row.node.type}
                                            onAdd={(type, handle) => {
                                                const result =
                                                    isLoop(row.node.type) && handle === 'body'
                                                        ? insertAfter(graph, row.node.id, type, 'body')
                                                        : insertAfter(graph, row.node.id, type, handle);
                                                setGraph(result.graph);
                                                setSelected(result.id);
                                            }}
                                        />
                                    )
                                }
                            />
                        </div>
                    )}
                </div>

                <aside className="flex min-w-0 flex-col gap-4">
                    {selectedNode ? (
                        <div className="rounded-xl border bg-card p-4">
                            <BlockInspector
                                key={selectedNode.id}
                                node={selectedNode}
                                readiness={readiness[selectedNode.id]}
                                agent={agent}
                                agents={agents}
                                people={people}
                                capabilities={capabilities}
                                skills={skills}
                                onChange={(data) => patch(selectedNode.id, data)}
                                onDelete={() => remove(selectedNode.id)}
                            />
                            {isLoop(selectedNode.type) && (
                                <div className="mt-4 border-t pt-4">
                                    <AddMenu
                                        node="loop-body"
                                        label="Pôr um bloco dentro do ciclo"
                                        onAdd={(type) => addInside(selectedNode.id, type)}
                                    />
                                </div>
                            )}
                        </div>
                    ) : (
                        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                            <h2 className="text-sm font-semibold">Verificação</h2>
                            {(['alone', 'chief', 'person', 'missing'] as const).map((state) => (
                                <div key={state} className="flex items-center justify-between gap-2">
                                    <ReadinessBadge state={state} />
                                    <span className="font-mono text-sm">{summary[state]}</span>
                                </div>
                            ))}
                            {problems.length > 0 && (
                                <div className="rounded-lg bg-status-danger/8 p-2.5 text-xs text-status-danger">
                                    {problems.map((problem) => (
                                        <p key={problem}>{problem}</p>
                                    ))}
                                </div>
                            )}
                            {Object.entries(readiness)
                                .filter(([, row]) => row.state === 'missing')
                                .map(([id, row]) => (
                                    <button
                                        key={id}
                                        type="button"
                                        onClick={() => setSelected(id)}
                                        className="rounded-lg border p-2 text-left text-xs hover:bg-accent/60"
                                    >
                                        <div className="font-medium">{graph.nodes.find((node) => node.id === id)?.data.label}</div>
                                        <div className="text-muted-foreground">{row.reasons[0]}</div>
                                    </button>
                                ))}
                            <p className="text-xs text-muted-foreground">
                                {agent
                                    ? `Ao activar, a Triagem passa estes emails ao ${agent.name}, que começa a tarefa sozinho e segue os blocos. O que não consegue passa ${form.fallback_user_id ? `a ${people.find((p) => String(p.id) === form.fallback_user_id)?.name}` : 'à chefia dele'}.`
                                    : 'Escolha o agente que trata o fluxo.'}
                            </p>
                            <p className="text-xs text-muted-foreground">Escolha um bloco para o configurar.</p>
                        </div>
                    )}
                    {workflow && workflow.status !== 'active' && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="self-start text-status-danger hover:text-status-danger"
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 />
                            Apagar fluxo
                        </Button>
                    )}
                </aside>
            </div>

            <FormDialog
                open={drafting.open}
                onOpenChange={(open) => setDrafting((current) => ({ ...current, open }))}
                title="Propor passos"
                description="Descreva o fluxo em palavras. O assistente propõe os blocos com as capacidades que o agente tem; pode editar tudo antes de guardar. Substitui os blocos actuais."
                submitLabel={drafting.busy ? 'A propor…' : 'Propor passos'}
                processing={drafting.busy}
                disabled={drafting.text.trim().length < 15}
                onSubmit={propose}
            >
                <Field id="draft" label="O fluxo de que precisa">
                    <Textarea
                        id="draft"
                        rows={6}
                        value={drafting.text}
                        onChange={(event) => setDrafting((current) => ({ ...current, text: event.target.value }))}
                        placeholder="Quando um cliente pede cotação, ver se já é cliente, registar a oportunidade, pedir preços a três fornecedores, esperar cinco dias e preparar a proposta; acima de 500 000 MZN a directora aprova."
                    />
                </Field>
            </FormDialog>

            {workflow && (
                <ConfirmDialog
                    open={confirmDelete}
                    onOpenChange={setConfirmDelete}
                    title="Apagar este fluxo?"
                    description="Os emails deste tipo voltam à regra de Definições. Um fluxo que já tratou emails não se apaga: pause-o."
                    confirmLabel="Apagar"
                    destructive
                    onConfirm={() => router.delete(`/workflows/${workflow.id}`)}
                />
            )}

            <p className="text-xs text-muted-foreground">
                <Link href="/workflows" className="hover:underline">
                    Voltar ao mapa dos fluxos
                </Link>
            </p>
        </AppLayout>
    );
}

function AddMenu({
    node,
    label = 'Adicionar',
    onAdd,
}: {
    node: BlockType | 'loop-body';
    label?: string;
    onAdd: (type: BlockType, handle: string | null) => void;
}) {
    const meta = node === 'loop-body' ? undefined : blocks[node];
    const ways: { handle: string | null; label: string }[] =
        node === 'loop-body'
            ? [{ handle: 'body', label: 'Dentro do ciclo' }]
            : meta?.handles
              ? meta.handles.map((handle) => ({ handle: handle.id, label: `Se ${handle.label.toLowerCase()}` }))
              : isLoop(node)
                ? [
                      { handle: 'body', label: 'Dentro do ciclo' },
                      { handle: null, label: 'Depois do ciclo' },
                  ]
                : [{ handle: null, label: 'Depois' }];

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button type="button" variant="ghost" size="sm" onClick={(event) => event.stopPropagation()} aria-label={label}>
                    <Plus />
                    {node === 'loop-body' ? label : null}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" onClick={(event) => event.stopPropagation()}>
                {ways.flatMap((way) =>
                    paletteOrder
                        .filter((type) => !(way.handle === 'body' && isLoop(type)))
                        .map((type) => (
                            <DropdownMenuItem key={`${way.handle}-${type}`} onSelect={() => onAdd(type, way.handle)}>
                                {ways.length > 1 ? `${way.label}: ` : ''}
                                {blocks[type].label}
                            </DropdownMenuItem>
                        )),
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
