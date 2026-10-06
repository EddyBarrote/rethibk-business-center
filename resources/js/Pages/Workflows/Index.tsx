import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Mail, Plus, Settings2, Workflow as WorkflowIcon } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { FlowSteps, ReadinessBadge, ReadinessBar, ReadinessLegend } from '@/Components/workflows/Readiness';
import { useCan } from '@/hooks/useCan';
import AppLayout from '@/Layouts/AppLayout';
import { ago, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { fixLabel, flatten, type Readiness, type ReadinessSummary, type WfGraph } from '@/lib/workflows';

interface CategoryRoute {
    value: string;
    label: string;
    source: 'workflow' | 'rule' | 'default' | 'none';
    agent: { id: number; name: string } | null;
    workflow_id: number | null;
    fallback: string | null;
}

interface WorkflowSummary {
    id: number;
    name: string;
    description: string | null;
    status: 'draft' | 'active' | 'paused';
    status_label: string;
    email_category: string | null;
    email_category_label: string | null;
    agent: { id: number; name: string; level: string; level_label: string } | null;
    fallback: string | null;
    graph: WfGraph;
    readiness: Record<string, Readiness>;
    summary: ReadinessSummary;
    problems: string[];
    updated_at: string;
    runs_30d: { total: number; completed: number };
    recent_runs: {
        id: number;
        status: string;
        status_label: string;
        node: string | null;
        task: { id: number; identifier: string; title: string } | null;
        started_at: string | null;
    }[];
    can_edit: boolean;
}

interface AgentRow {
    id: number;
    name: string;
    title: string | null;
    active: boolean;
    level: string;
    level_label: string;
    capabilities: string[];
}

interface Props {
    categories: CategoryRoute[];
    workflows: WorkflowSummary[];
    agents: AgentRow[];
    can_create: boolean;
}

const statusTone = (status: string): Tone => (({ active: 'success', draft: 'idle', paused: 'warning' }) as Record<string, Tone>)[status] ?? 'idle';
const runTone = (status: string): Tone =>
    (({ running: 'running', waiting: 'warning', blocked: 'danger', completed: 'success', cancelled: 'idle' }) as Record<string, Tone>)[status] ??
    'idle';

const work = (summary: ReadinessSummary) => summary.alone + summary.chief + summary.person + summary.missing;

function routeLine(category: CategoryRoute): string {
    if (category.source === 'none') {
        return 'Fica com a Triagem e a pessoa notificada';
    }

    return category.agent ? `${category.agent.name}${category.source === 'workflow' ? '' : ' · sem fluxo'}` : '—';
}

export default function WorkflowsIndex({ categories, workflows, agents, can_create }: Props) {
    const can = useCan();
    const initial = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('w');
    const byId = useMemo(() => new Map(workflows.map((workflow) => [workflow.id, workflow])), [workflows]);
    const [selected, setSelected] = useState<{ category?: string; workflow?: number }>(() =>
        initial && byId.has(Number(initial))
            ? { workflow: Number(initial) }
            : { category: categories.find((category) => category.workflow_id)?.value ?? categories[0]?.value },
    );

    const category = categories.find((row) => row.value === selected.category);
    const workflow = selected.workflow ? byId.get(selected.workflow) : category?.workflow_id ? byId.get(category.workflow_id) : undefined;
    const others = workflows.filter((row) => row.status !== 'active');
    const gaps = workflows.flatMap((row) =>
        Object.entries(row.readiness)
            .filter(([, ready]) => ready.state === 'missing')
            .map(([node, ready]) => ({ workflow: row, node, ready, label: row.graph.nodes.find((n) => n.id === node)?.data.label ?? node })),
    );

    return (
        <AppLayout>
            <Head title="Fluxos de trabalho" />

            <PageHeader
                title="Fluxos de trabalho"
                description="O que acontece a cada tipo de email depois da triagem, calculado a partir do que cada agente tem."
                actions={
                    <>
                        {can('agents.manage') && (
                            <Button variant="outline" asChild>
                                <Link href="/settings/email-rules">
                                    <Settings2 />
                                    Regras de email
                                </Link>
                            </Button>
                        )}
                        {can_create && (
                            <Button asChild>
                                <Link href={`/workflows/new${category && !category.workflow_id ? `?category=${category.value}` : ''}`}>
                                    <Plus />
                                    Novo fluxo
                                </Link>
                            </Button>
                        )}
                    </>
                }
            />

            <Tabs defaultValue="types" className="mt-6">
                <div className="flex flex-col gap-3 border-b lg:flex-row lg:items-end lg:justify-between">
                    <TabsList variant="line" className="scroll-fade overflow-x-auto">
                        <TabsTrigger value="types" className="flex-none">
                            Por tipo de email
                        </TabsTrigger>
                        <TabsTrigger value="agents" className="flex-none">
                            Por agente
                        </TabsTrigger>
                        <TabsTrigger value="gaps" className="flex-none">
                            Lacunas
                            {gaps.length > 0 && <span className="ml-1.5 font-semibold text-primary tabular-nums">{gaps.length}</span>}
                        </TabsTrigger>
                    </TabsList>
                    <ReadinessLegend className="pb-2" />
                </div>

                <TabsContent value="types" className="mt-5">
                    <div className="grid gap-5 lg:grid-cols-[19rem_minmax(0,1fr)] xl:grid-cols-[19rem_minmax(0,1fr)_17rem]">
                        <div className="flex min-w-0 flex-col gap-5">
                            <Section title={`Tipos de email · ${categories.length}`}>
                                <div className="divide-y overflow-hidden rounded-xl border bg-card">
                                    {categories.map((row) => {
                                        const flow = row.workflow_id ? byId.get(row.workflow_id) : undefined;
                                        const active = !selected.workflow && selected.category === row.value;

                                        return (
                                            <button
                                                key={row.value}
                                                type="button"
                                                onClick={() => setSelected({ category: row.value })}
                                                className={cn(
                                                    'block w-full px-4 py-2.5 text-left transition-colors hover:bg-accent/60',
                                                    active && 'bg-accent',
                                                )}
                                            >
                                                <div className="flex items-baseline justify-between gap-2">
                                                    <span className="truncate text-sm font-medium">{row.label}</span>
                                                    <span className="shrink-0 font-mono text-[11px] text-muted-foreground">
                                                        {flow ? plural(work(flow.summary), 'passo', 'passos') : 'sem fluxo'}
                                                    </span>
                                                </div>
                                                <div className="truncate text-xs text-muted-foreground">{routeLine(row)}</div>
                                                {flow && <ReadinessBar summary={flow.summary} className="mt-1.5" />}
                                            </button>
                                        );
                                    })}
                                </div>
                            </Section>

                            {others.length > 0 && (
                                <Section title="Rascunhos e pausados">
                                    <div className="divide-y overflow-hidden rounded-xl border bg-card">
                                        {others.map((row) => (
                                            <button
                                                key={row.id}
                                                type="button"
                                                onClick={() => setSelected({ workflow: row.id })}
                                                className={cn(
                                                    'block w-full px-4 py-2.5 text-left transition-colors hover:bg-accent/60',
                                                    selected.workflow === row.id && 'bg-accent',
                                                )}
                                            >
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="truncate text-sm font-medium">{row.name}</span>
                                                    <StatusBadge tone={statusTone(row.status)}>{row.status_label}</StatusBadge>
                                                </div>
                                                <div className="truncate text-xs text-muted-foreground">
                                                    {row.email_category_label} · {row.agent?.name ?? 'sem agente'}
                                                </div>
                                                <ReadinessBar summary={row.summary} className="mt-1.5" />
                                            </button>
                                        ))}
                                    </div>
                                </Section>
                            )}
                        </div>

                        <Section title="Fluxo" className="min-w-0">
                            {workflow ? (
                                <div className="overflow-hidden rounded-xl border bg-card">
                                    <div className="flex flex-wrap items-center gap-2 border-b px-4 py-3 text-sm">
                                        <span className="inline-flex items-center gap-1.5 rounded-lg border bg-background px-2 py-1">
                                            <Mail className="size-3.5 text-muted-foreground" />
                                            {workflow.email_category_label}
                                        </span>
                                        <ArrowRight className="size-3.5 text-muted-foreground" />
                                        <span className="rounded-lg border bg-background px-2 py-1">A Triagem classifica</span>
                                        <ArrowRight className="size-3.5 text-muted-foreground" />
                                        <span className="rounded-lg border border-primary/50 bg-background px-2 py-1">
                                            {workflow.agent?.name ?? 'Sem agente'} abre a tarefa e começa
                                        </span>
                                        {workflow.agent && (
                                            <StatusBadge tone="running" dot={false} className="ml-auto">
                                                {workflow.agent.level} · {workflow.agent.level_label}
                                            </StatusBadge>
                                        )}
                                    </div>
                                    {workflow.problems.length > 0 && (
                                        <div className="border-b bg-status-danger/8 px-4 py-2 text-xs text-status-danger">{workflow.problems[0]}</div>
                                    )}
                                    <FlowSteps rows={flatten(workflow.graph)} readiness={workflow.readiness} agents={agents} />
                                </div>
                            ) : category ? (
                                <EmptyState
                                    icon={WorkflowIcon}
                                    title={`«${category.label}» não tem fluxo`}
                                    description={
                                        category.agent
                                            ? `A Triagem passa estes emails ao ${category.agent.name}, que abre a tarefa e começa sozinho, com o que sabe. Um fluxo diz-lhe os passos e o que precisa de uma pessoa.`
                                            : 'A Triagem classifica estes emails e avisa a pessoa certa; nenhum agente os trata. Um fluxo dá-os a um agente, com os passos.'
                                    }
                                    action={
                                        can_create && (
                                            <Button asChild>
                                                <Link href={`/workflows/new?category=${category.value}`}>
                                                    <Plus />
                                                    Criar fluxo para este tipo
                                                </Link>
                                            </Button>
                                        )
                                    }
                                />
                            ) : null}
                        </Section>

                        {workflow && (
                            <div className="flex min-w-0 flex-col gap-4 lg:col-span-2 xl:col-span-1">
                                <Properties title={workflow.name}>
                                    <Property label="Estado">
                                        <StatusBadge tone={statusTone(workflow.status)}>{workflow.status_label}</StatusBadge>
                                    </Property>
                                    <Property label="Quem trata">
                                        {workflow.agent ? (
                                            <Link href={`/agents/${workflow.agent.id}`} className="hover:underline">
                                                {workflow.agent.name}
                                            </Link>
                                        ) : null}
                                    </Property>
                                    <Property label="Nível">
                                        {workflow.agent ? `${workflow.agent.level} · ${workflow.agent.level_label}` : null}
                                    </Property>
                                    <Property label="Se não conseguir">{workflow.fallback ?? 'A chefia do agente'}</Property>
                                    <Property label="Últimos 30 dias">
                                        {plural(workflow.runs_30d.total, 'email', 'emails')} · {workflow.runs_30d.completed} concluídos
                                    </Property>
                                    <Property label="Alterado">{ago(workflow.updated_at)}</Property>
                                    <div className="mt-2 space-y-1.5 border-t pt-3">
                                        {(['alone', 'chief', 'person', 'missing'] as const).map((state) =>
                                            workflow.summary[state] > 0 ? (
                                                <div key={state} className="flex items-center justify-between gap-2 text-sm">
                                                    <ReadinessBadge state={state} />
                                                    <span className="font-mono tabular-nums">{workflow.summary[state]}</span>
                                                </div>
                                            ) : null,
                                        )}
                                    </div>
                                    {workflow.can_edit && (
                                        <div className="pt-2">
                                            <Button variant="outline" size="sm" asChild>
                                                <Link href={`/workflows/${workflow.id}/edit`}>Editar fluxo</Link>
                                            </Button>
                                        </div>
                                    )}
                                </Properties>

                                {workflow.recent_runs.length > 0 && (
                                    <Section title="Emails recentes">
                                        <div className="divide-y overflow-hidden rounded-xl border bg-card">
                                            {workflow.recent_runs.map((run) => (
                                                <Link
                                                    key={run.id}
                                                    href={run.task ? `/tasks/${run.task.id}` : '#'}
                                                    className="block px-3 py-2 text-sm hover:bg-accent/60"
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="truncate font-mono text-xs">{run.task?.identifier ?? `#${run.id}`}</span>
                                                        <StatusBadge tone={runTone(run.status)}>{run.status_label}</StatusBadge>
                                                    </div>
                                                    <div className="truncate text-xs text-muted-foreground">
                                                        {run.node ? `Em «${run.node}»` : run.task?.title}
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    </Section>
                                )}
                            </div>
                        )}
                    </div>
                </TabsContent>

                <TabsContent value="agents" className="mt-5">
                    <div className="divide-y overflow-hidden rounded-xl border bg-card">
                        {agents.map((agent) => {
                            const leads = workflows.filter((row) => row.agent?.id === agent.id);
                            const helps = workflows.filter(
                                (row) => row.agent?.id !== agent.id && row.graph.nodes.some((node) => node.data.agent_id === agent.id),
                            );
                            const routes = categories.filter((row) => row.agent?.id === agent.id);

                            return (
                                <div key={agent.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-start">
                                    <div className="flex min-w-0 items-center gap-3 sm:w-72">
                                        <Monogram name={agent.name} agent />
                                        <div className="min-w-0">
                                            <Link href={`/agents/${agent.id}`} className="block truncate text-sm font-medium hover:underline">
                                                {agent.name}
                                            </Link>
                                            <div className="truncate text-xs text-muted-foreground">
                                                {agent.level} · {plural(agent.capabilities.length, 'capacidade', 'capacidades')}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="min-w-0 flex-1 space-y-1 text-sm">
                                        {routes.length > 0 && (
                                            <div className="text-xs text-muted-foreground">Recebe: {routes.map((row) => row.label).join(', ')}</div>
                                        )}
                                        {leads.map((row) => (
                                            <div key={row.id} className="flex items-center gap-3">
                                                <button
                                                    type="button"
                                                    className="min-w-0 truncate text-left hover:underline"
                                                    onClick={() => setSelected({ workflow: row.id })}
                                                >
                                                    {row.name}
                                                </button>
                                                <ReadinessBar summary={row.summary} className="w-28 shrink-0" />
                                            </div>
                                        ))}
                                        {helps.map((row) => (
                                            <div key={row.id} className="text-xs text-muted-foreground">
                                                Ajuda em «{row.name}» ({row.agent?.name})
                                            </div>
                                        ))}
                                        {routes.length === 0 && leads.length === 0 && helps.length === 0 && (
                                            <div className="text-xs text-muted-foreground">Não entra em nenhum fluxo de email.</div>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </TabsContent>

                <TabsContent value="gaps" className="mt-5">
                    {gaps.length === 0 ? (
                        <EmptyState
                            icon={WorkflowIcon}
                            title="Sem lacunas"
                            description="Todos os passos dos fluxos têm um agente com o que precisa, ou uma pessoa que decide."
                        />
                    ) : (
                        <div className="divide-y overflow-hidden rounded-xl border bg-card">
                            {gaps.map((gap) => (
                                <div key={`${gap.workflow.id}-${gap.node}`} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center">
                                    <div className="min-w-0 flex-1">
                                        <div className="text-sm font-medium">{gap.label}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {gap.workflow.name} · {gap.ready.reasons.join(' ')}
                                        </div>
                                        {gap.ready.fixes.length > 0 && (
                                            <div className="mt-1 text-xs text-primary">
                                                {gap.ready.fixes.map((fix) => fixLabel[fix] ?? fix).join(' · ')}
                                            </div>
                                        )}
                                    </div>
                                    {gap.workflow.can_edit && (
                                        <Button variant="outline" size="sm" asChild>
                                            <Link href={`/workflows/${gap.workflow.id}/edit?node=${gap.node}`}>Abrir o passo</Link>
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </TabsContent>
            </Tabs>
        </AppLayout>
    );
}
