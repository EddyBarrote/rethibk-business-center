import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    CalendarClock,
    ChevronLeft,
    ChevronRight,
    CircleAlert,
    Inbox,
    Mail,
    MoreHorizontal,
    Pause,
    Pencil,
    Play,
    Plus,
    Search,
    SearchX,
    Settings2,
    TriangleAlert,
    Workflow as WorkflowIcon,
    X,
} from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';

import { ListPanel, Monogram, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, StatusDot, toneFill, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/Components/ui/sheet';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { FlowSteps, ReadinessBar } from '@/Components/workflows/Readiness';
import { useCan } from '@/hooks/useCan';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import { fixLabel, flatten, type Readiness, readinessLabel, readinessTone, type ReadinessSummary, type WfGraph } from '@/lib/workflows';

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
    trigger: 'email' | 'schedule';
    trigger_label: string;
    routines: { id: number; name: string; schedule_label: string; is_active: boolean }[];
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

type View = 'tipos' | 'agentes' | 'lacunas';
type Choice = { category: string } | { workflow: number } | null;

const views: View[] = ['tipos', 'agentes', 'lacunas'];
const states = ['alone', 'chief', 'person', 'missing'] as const;

const statusTone = (status: string): Tone => (({ active: 'success', draft: 'idle', paused: 'warning' }) as Record<string, Tone>)[status] ?? 'idle';
const runTone = (status: string): Tone =>
    (({ running: 'running', waiting: 'warning', blocked: 'danger', completed: 'success', cancelled: 'idle' }) as Record<string, Tone>)[status] ??
    'idle';

const work = (summary: ReadinessSummary) => summary.alone + summary.chief + summary.person + summary.missing;
const schedules = (row: WorkflowSummary) =>
    row.routines
        .filter((routine) => routine.is_active)
        .map((routine) => routine.schedule_label)
        .join(' · ') || 'Sem horário activo';

/** Who handles a type of email today, in words. */
function routeLine(category: CategoryRoute): string {
    if (category.source === 'none' || !category.agent) {
        return 'Sem agente · fica com a Triagem';
    }

    return category.agent.name;
}

/** Below lg the detail opens over the list instead of beside it. */
function useWide(): boolean {
    const query = '(min-width: 1024px)';
    const [wide, setWide] = useState(() => typeof window === 'undefined' || window.matchMedia(query).matches);

    useEffect(() => {
        const media = window.matchMedia(query);
        const change = () => setWide(media.matches);
        media.addEventListener('change', change);

        return () => media.removeEventListener('change', change);
    }, []);

    return wide;
}

function readUrl(workflows: WorkflowSummary[], categories: CategoryRoute[]): { view: View; choice: Choice; q: string } {
    const params = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search);
    const view = views.includes(params.get('vista') as View) ? (params.get('vista') as View) : 'tipos';
    // ?w= is the older link (Regras de email, the task's flow card).
    const flow = Number(params.get('fluxo') ?? params.get('w'));
    const type = params.get('tipo');
    const choice: Choice = workflows.some((row) => row.id === flow)
        ? { workflow: flow }
        : categories.some((row) => row.value === type)
          ? { category: type as string }
          : null;

    return { view, choice, q: params.get('q') ?? '' };
}

export default function WorkflowsIndex({ categories, workflows, agents, can_create }: Props) {
    const can = useCan();
    const wide = useWide();
    const [initial] = useState(() => readUrl(workflows, categories));
    const [view, setView] = useState<View>(initial.view);
    const [choice, setChoice] = useState<Choice>(initial.choice);
    const [q, setQ] = useState(initial.q);

    const byId = useMemo(() => new Map(workflows.map((workflow) => [workflow.id, workflow])), [workflows]);
    const withFlow = categories.filter((row) => row.workflow_id);
    const withoutFlow = categories.filter((row) => !row.workflow_id);
    const scheduled = workflows.filter((row) => row.trigger === 'schedule');
    const resting = workflows.filter((row) => row.trigger === 'email' && row.status !== 'active');
    const gaps = workflows.flatMap((row) =>
        Object.entries(row.readiness)
            .filter(([, ready]) => ready.state === 'missing')
            .map(([node, ready]) => ({ workflow: row, node, ready, label: row.graph.nodes.find((n) => n.id === node)?.data.label ?? node })),
    );
    const gapFlows = [...new Set(gaps.map((gap) => gap.workflow.id))].map((id) => byId.get(id) as WorkflowSummary);
    const active = workflows.filter((row) => row.status === 'active');
    const runs = workflows.reduce((sum, row) => ({ total: sum.total + row.runs_30d.total, completed: sum.completed + row.runs_30d.completed }), {
        total: 0,
        completed: 0,
    });

    // On a wide screen something is always open: the first flow, else the first type.
    const fallback: Choice = withFlow[0]
        ? { category: withFlow[0].value }
        : workflows[0]
          ? { workflow: workflows[0].id }
          : categories[0]
            ? { category: categories[0].value }
            : null;
    const shown = choice ?? (wide ? fallback : null);
    const shownCategory = shown && 'category' in shown ? categories.find((row) => row.value === shown.category) : undefined;
    const shownFlow =
        shown && 'workflow' in shown ? byId.get(shown.workflow) : shownCategory?.workflow_id ? byId.get(shownCategory.workflow_id) : undefined;

    useEffect(() => {
        const params = new URLSearchParams();

        if (view !== 'tipos') {
            params.set('vista', view);
        }

        if (choice && 'workflow' in choice) {
            params.set('fluxo', String(choice.workflow));
        } else if (choice) {
            params.set('tipo', choice.category);
        }

        if (q.trim()) {
            params.set('q', q.trim());
        }

        const search = params.toString();
        window.history.replaceState(window.history.state, '', `/settings/workflows${search ? `?${search}` : ''}`);
    }, [view, choice, q]);

    const open = (next: Choice) => {
        setChoice(next);
        setView('tipos');
    };

    const words = q.trim().toLowerCase().split(/\s+/).filter(Boolean);
    const matches = (...texts: (string | null | undefined)[]) => {
        const haystack = texts.filter(Boolean).join(' ').toLowerCase();

        return words.every((word) => haystack.includes(word));
    };
    const shownWithFlow = withFlow.filter((row) => matches(row.label, row.agent?.name, byId.get(row.workflow_id as number)?.name));
    const shownScheduled = scheduled.filter((row) => matches(row.name, row.agent?.name, schedules(row)));
    const shownResting = resting.filter((row) => matches(row.name, row.agent?.name, row.email_category_label));
    const shownWithout = withoutFlow.filter((row) => matches(row.label, routeLine(row)));
    const nothing = shownWithFlow.length + shownScheduled.length + shownResting.length + shownWithout.length === 0;

    const isOn = (row: Choice) => !!shown && !!row && JSON.stringify(row) === JSON.stringify(shown);

    const detail = shownFlow ? (
        <FlowDetail workflow={shownFlow} agents={agents} onClose={wide ? undefined : () => setChoice(null)} />
    ) : shownCategory ? (
        <NoFlow category={shownCategory} canCreate={can_create} onClose={wide ? undefined : () => setChoice(null)} />
    ) : null;

    return (
        <SettingsLayout>
            <Head title="Fluxos de trabalho" />

            <div className="min-w-0">
                <PageHeader
                    title="Fluxos de trabalho"
                    description="O que cada agente faz com cada tipo de email depois da triagem, e os fluxos que começam por agenda."
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
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button>
                                            <Plus />
                                            Novo fluxo
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end" className="w-64">
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href={`/settings/workflows/new${shownCategory && !shownCategory.workflow_id ? `?category=${shownCategory.value}` : ''}`}
                                            >
                                                <Mail />
                                                <div>
                                                    <div>Para um tipo de email</div>
                                                    <div className="text-xs text-muted-foreground">Começa depois da triagem</div>
                                                </div>
                                            </Link>
                                        </DropdownMenuItem>
                                        <DropdownMenuItem asChild>
                                            <Link href="/settings/workflows/new?trigger=schedule">
                                                <CalendarClock />
                                                <div>
                                                    <div>Por agenda</div>
                                                    <div className="text-xs text-muted-foreground">Começa a horas certas, nas rotinas</div>
                                                </div>
                                            </Link>
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            )}
                        </>
                    }
                />

                <div className="mt-6 grid grid-cols-2 gap-px overflow-hidden rounded-xl border bg-border xl:grid-cols-4">
                    <Metric value={`${withFlow.length}/${categories.length}`} label="Tipos com fluxo" hint="Os outros seguem a regra de email" />
                    <Metric
                        value={active.length}
                        label="Fluxos activos"
                        hint={[
                            plural(workflows.filter((row) => row.status === 'draft').length, 'rascunho', 'rascunhos'),
                            `${workflows.filter((row) => row.status === 'paused').length} em pausa`,
                        ].join(' · ')}
                    />
                    <Metric
                        value={gaps.length}
                        label="Lacunas"
                        hint={
                            gaps.length
                                ? `Passos sem capacidade, em ${plural(gapFlows.length, 'fluxo', 'fluxos')}`
                                : 'Todos os passos têm quem os faça'
                        }
                        tone={gaps.length ? 'danger' : undefined}
                        onClick={gaps.length ? () => setView('lacunas') : undefined}
                    />
                    <Metric value={runs.total} label="Execuções, 30 dias" hint={`${runs.completed} concluídas`} />
                </div>

                <Tabs value={view} onValueChange={(value) => setView(value as View)} className="mt-6">
                    <TabsList variant="line" className="scroll-fade w-full justify-start overflow-x-auto border-b">
                        <TabsTrigger value="tipos" className="flex-none">
                            Fluxos
                            <span className="ml-1.5 text-muted-foreground tabular-nums">{workflows.length}</span>
                        </TabsTrigger>
                        <TabsTrigger value="agentes" className="flex-none">
                            Por agente
                            <span className="ml-1.5 text-muted-foreground tabular-nums">{agents.length}</span>
                        </TabsTrigger>
                        <TabsTrigger value="lacunas" className="flex-none">
                            Lacunas
                            {gaps.length > 0 && <span className="ml-1.5 font-semibold text-status-danger tabular-nums">{gaps.length}</span>}
                        </TabsTrigger>
                    </TabsList>
                </Tabs>

                {view === 'tipos' && (
                    <div className="mt-5 grid items-start gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]">
                        <div className="flex min-w-0 flex-col gap-5 lg:sticky lg:top-4">
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={q}
                                    onChange={(event) => setQ(event.target.value)}
                                    placeholder="Procurar tipo, fluxo ou agente"
                                    className="pr-8 pl-8"
                                    aria-label="Procurar"
                                />
                                {q && (
                                    <button
                                        type="button"
                                        onClick={() => setQ('')}
                                        className="absolute top-1/2 right-2 -translate-y-1/2 rounded p-0.5 text-muted-foreground hover:text-foreground"
                                        aria-label="Limpar pesquisa"
                                    >
                                        <X className="size-4" />
                                    </button>
                                )}
                            </div>

                            {nothing && (
                                <EmptyState icon={SearchX} title="Nada encontrado" description={`Nenhum tipo, fluxo ou agente com «${q.trim()}».`} />
                            )}

                            {shownWithFlow.length > 0 && (
                                <Section title={`Email com fluxo · ${shownWithFlow.length}`}>
                                    <ListPanel>
                                        {shownWithFlow.map((row) => {
                                            const flow = byId.get(row.workflow_id as number) as WorkflowSummary;

                                            return (
                                                <PickRow
                                                    key={row.value}
                                                    on={isOn({ category: row.value })}
                                                    onClick={() => open({ category: row.value })}
                                                    icon={Mail}
                                                    title={row.label}
                                                    subtitle={flow.agent?.name ?? 'Sem agente'}
                                                    trailing={<StepCount flow={flow} />}
                                                    bar={flow.summary}
                                                />
                                            );
                                        })}
                                    </ListPanel>
                                </Section>
                            )}

                            {(shownScheduled.length > 0 || (!words.length && scheduled.length === 0)) && (
                                <Section
                                    title={`Por agenda · ${shownScheduled.length}`}
                                    action={
                                        can_create && (
                                            <Link href="/settings/workflows/new?trigger=schedule" className="text-xs text-primary hover:underline">
                                                Novo
                                            </Link>
                                        )
                                    }
                                >
                                    {scheduled.length === 0 ? (
                                        <p className="rounded-xl border border-dashed px-4 py-3 text-xs text-muted-foreground">
                                            Nenhum ainda. Um fluxo por agenda começa a horas certas, sem esperar por um email.
                                        </p>
                                    ) : (
                                        <ListPanel>
                                            {shownScheduled.map((row) => (
                                                <PickRow
                                                    key={row.id}
                                                    on={isOn({ workflow: row.id })}
                                                    onClick={() => open({ workflow: row.id })}
                                                    icon={CalendarClock}
                                                    title={row.name}
                                                    subtitle={schedules(row)}
                                                    trailing={
                                                        row.status === 'active' ? (
                                                            <StepCount flow={row} />
                                                        ) : (
                                                            <StatusBadge tone={statusTone(row.status)}>{row.status_label}</StatusBadge>
                                                        )
                                                    }
                                                    bar={row.summary}
                                                />
                                            ))}
                                        </ListPanel>
                                    )}
                                </Section>
                            )}

                            {shownResting.length > 0 && (
                                <Section title={`Rascunhos e em pausa · ${shownResting.length}`}>
                                    <ListPanel>
                                        {shownResting.map((row) => (
                                            <PickRow
                                                key={row.id}
                                                on={isOn({ workflow: row.id })}
                                                onClick={() => open({ workflow: row.id })}
                                                icon={Mail}
                                                title={row.name}
                                                subtitle={row.email_category_label ?? 'Sem tipo de email'}
                                                trailing={<StatusBadge tone={statusTone(row.status)}>{row.status_label}</StatusBadge>}
                                                bar={row.summary}
                                            />
                                        ))}
                                    </ListPanel>
                                </Section>
                            )}

                            {shownWithout.length > 0 && (
                                <Section title={`Email sem fluxo · ${shownWithout.length}`}>
                                    <ListPanel>
                                        {shownWithout.map((row) => (
                                            <PickRow
                                                key={row.value}
                                                on={isOn({ category: row.value })}
                                                onClick={() => open({ category: row.value })}
                                                icon={Inbox}
                                                title={row.label}
                                                subtitle={routeLine(row)}
                                                muted
                                            />
                                        ))}
                                    </ListPanel>
                                </Section>
                            )}
                        </div>

                        {wide && <div className="min-w-0">{detail}</div>}
                    </div>
                )}

                {view === 'agentes' && <AgentsView agents={agents} workflows={workflows} categories={categories} onOpen={open} />}

                {view === 'lacunas' && (
                    <div className="mt-5">
                        {gaps.length === 0 ? (
                            <EmptyState
                                icon={WorkflowIcon}
                                title="Sem lacunas"
                                description="Todos os passos dos fluxos têm um agente com o que precisa, ou uma pessoa que decide."
                            />
                        ) : (
                            <div className="flex flex-col gap-5">
                                <p className="text-sm text-muted-foreground">
                                    Passos que nenhum agente consegue fazer hoje. Até serem resolvidos, vão à pessoa de recurso do fluxo.
                                </p>
                                {gapFlows.map((flow) => (
                                    <Section
                                        key={flow.id}
                                        title={`${flow.name} · ${gaps.filter((gap) => gap.workflow.id === flow.id).length}`}
                                        action={
                                            <button
                                                type="button"
                                                onClick={() => open({ workflow: flow.id })}
                                                className="text-xs text-primary hover:underline"
                                            >
                                                Ver o fluxo
                                            </button>
                                        }
                                    >
                                        <ListPanel>
                                            {gaps
                                                .filter((gap) => gap.workflow.id === flow.id)
                                                .map((gap) => (
                                                    <div key={gap.node} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start">
                                                        <span className="mt-0.5 hidden size-6 shrink-0 items-center justify-center rounded-md bg-status-danger/12 text-status-danger sm:inline-flex">
                                                            <CircleAlert className="size-3.5" />
                                                        </span>
                                                        <div className="min-w-0 flex-1">
                                                            <div className="text-sm font-medium">{gap.label}</div>
                                                            <p className="mt-0.5 text-xs text-muted-foreground">{gap.ready.reasons.join(' ')}</p>
                                                            {gap.ready.fixes.length > 0 && (
                                                                <div className="mt-2 flex flex-wrap gap-1.5">
                                                                    {gap.ready.fixes.map((fix) => (
                                                                        <span
                                                                            key={fix}
                                                                            className="rounded-md border bg-background px-2 py-0.5 text-xs"
                                                                        >
                                                                            {fixLabel[fix] ?? fix}
                                                                        </span>
                                                                    ))}
                                                                </div>
                                                            )}
                                                        </div>
                                                        {gap.workflow.can_edit && (
                                                            <Button variant="outline" size="sm" asChild className="self-start">
                                                                <Link href={`/settings/workflows/${gap.workflow.id}/edit?node=${gap.node}`}>
                                                                    Abrir o passo
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </div>
                                                ))}
                                        </ListPanel>
                                    </Section>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            <Sheet open={!wide && view === 'tipos' && choice !== null} onOpenChange={(next) => !next && setChoice(null)}>
                <SheetContent
                    side="right"
                    showCloseButton={false}
                    onOpenAutoFocus={(event) => event.preventDefault()}
                    className="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl"
                >
                    <SheetTitle className="sr-only">{shownFlow?.name ?? shownCategory?.label ?? 'Fluxo'}</SheetTitle>
                    <SheetDescription className="sr-only">Os passos do fluxo e o que acontece em cada um.</SheetDescription>
                    {!wide && detail}
                </SheetContent>
            </Sheet>
        </SettingsLayout>
    );
}

function Metric({ value, label, hint, tone, onClick }: { value: ReactNode; label: string; hint: string; tone?: 'danger'; onClick?: () => void }) {
    const body = (
        <>
            <span className={cn('text-2xl font-semibold tracking-tight tabular-nums', tone === 'danger' && 'text-status-danger')}>{value}</span>
            <span className="text-sm font-medium">{label}</span>
            <span className="text-xs text-muted-foreground">{hint}</span>
        </>
    );
    const classes = 'flex min-w-0 flex-col gap-0.5 bg-card px-4 py-3 text-left sm:px-5 sm:py-4';

    return onClick ? (
        <button type="button" onClick={onClick} className={cn(classes, 'transition-colors hover:bg-accent/60')}>
            {body}
        </button>
    ) : (
        <div className={classes}>{body}</div>
    );
}

function StepCount({ flow }: { flow: WorkflowSummary }) {
    return flow.summary.missing > 0 ? (
        <span className="inline-flex items-center gap-1 text-xs font-medium text-status-danger tabular-nums">
            <TriangleAlert className="size-3" />
            {flow.summary.missing}
        </span>
    ) : (
        <span className="text-xs text-muted-foreground tabular-nums">{plural(work(flow.summary), 'passo', 'passos')}</span>
    );
}

function PickRow({
    on,
    onClick,
    icon: Icon,
    title,
    subtitle,
    trailing,
    bar,
    muted = false,
}: {
    on: boolean;
    onClick: () => void;
    icon: typeof Mail;
    title: string;
    subtitle: string;
    trailing?: ReactNode;
    bar?: ReadinessSummary;
    muted?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={on || undefined}
            className={cn(
                'relative flex w-full items-start gap-3 px-4 py-2.5 text-left transition-colors hover:bg-accent/60',
                on && 'bg-accent before:absolute before:inset-y-0 before:left-0 before:w-0.5 before:bg-primary',
            )}
        >
            <span
                className={cn(
                    'mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-md',
                    muted ? 'bg-muted text-muted-foreground' : 'bg-primary/12 text-primary',
                )}
            >
                <Icon className="size-3.5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="flex items-center justify-between gap-2">
                    <span className={cn('truncate text-sm font-medium', muted && 'font-normal')}>{title}</span>
                    {trailing}
                </span>
                <span className="block truncate text-xs text-muted-foreground">{subtitle}</span>
                {bar && <ReadinessBar summary={bar} className="mt-1.5" />}
            </span>
            <ChevronRight className="mt-1 size-4 shrink-0 text-muted-foreground lg:hidden" />
        </button>
    );
}

function CloseButton({ onClose }: { onClose?: () => void }) {
    return onClose ? (
        <Button variant="ghost" size="sm" onClick={onClose} className="-ml-2 self-start text-muted-foreground">
            <ChevronLeft />
            Fluxos
        </Button>
    ) : null;
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 bg-card px-4 py-3">
            <div className="text-xs text-muted-foreground">{label}</div>
            <div className="mt-0.5 text-sm font-medium break-words">{children}</div>
        </div>
    );
}

function FlowDetail({ workflow, agents, onClose }: { workflow: WorkflowSummary; agents: AgentRow[]; onClose?: () => void }) {
    const total = work(workflow.summary);
    const setStatus = (status: 'active' | 'paused') =>
        router.put(`/settings/workflows/${workflow.id}/status`, { status }, { preserveScroll: true, preserveState: true });

    return (
        <div className={cn('@container flex flex-col', !onClose && 'overflow-hidden rounded-xl border bg-card')}>
            <div className="flex flex-col gap-3 border-b px-4 py-4 sm:px-5">
                <CloseButton onClose={onClose} />
                <div className="flex items-start gap-3">
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-base font-semibold tracking-tight break-words">{workflow.name}</h2>
                            <StatusBadge tone={statusTone(workflow.status)}>{workflow.status_label}</StatusBadge>
                        </div>
                        {workflow.description && <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{workflow.description}</p>}
                    </div>
                    {workflow.can_edit && (
                        <div className="flex shrink-0 items-center gap-1.5">
                            <Button variant="outline" size="sm" asChild>
                                <Link href={`/settings/workflows/${workflow.id}/edit`}>
                                    <Pencil />
                                    <span className="hidden sm:inline">Editar</span>
                                </Link>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="ghost" size="icon" className="size-8" aria-label="Mais acções">
                                        <MoreHorizontal />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    {workflow.status === 'active' ? (
                                        <DropdownMenuItem onSelect={() => setStatus('paused')}>
                                            <Pause />
                                            Pausar
                                        </DropdownMenuItem>
                                    ) : (
                                        <DropdownMenuItem onSelect={() => setStatus('active')} disabled={workflow.problems.length > 0}>
                                            <Play />
                                            Activar
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuSeparator />
                                    {workflow.trigger === 'schedule' ? (
                                        <DropdownMenuItem asChild>
                                            <Link href="/settings/routines">
                                                <CalendarClock />
                                                Ver as rotinas
                                            </Link>
                                        </DropdownMenuItem>
                                    ) : (
                                        <DropdownMenuItem asChild>
                                            <Link href="/settings/email-rules">
                                                <Settings2 />
                                                Ver as regras de email
                                            </Link>
                                        </DropdownMenuItem>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    {workflow.trigger === 'schedule' ? (
                        <Chip icon={<CalendarClock className="size-3.5 text-muted-foreground" />}>{schedules(workflow)}</Chip>
                    ) : (
                        <>
                            <Chip icon={<Mail className="size-3.5 text-muted-foreground" />}>{workflow.email_category_label}</Chip>
                            <ArrowRight className="size-3 text-muted-foreground" />
                            <Chip>A Triagem classifica</Chip>
                        </>
                    )}
                    <ArrowRight className="size-3 text-muted-foreground" />
                    <Chip className="border-primary/40 bg-primary/8">{workflow.agent?.name ?? 'Sem agente'} abre a tarefa e começa</Chip>
                </div>
            </div>

            {workflow.problems.length > 0 && (
                <div className="flex items-start gap-2 border-b bg-status-danger/8 px-4 py-2.5 text-xs text-status-danger sm:px-5">
                    <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                    <span>{workflow.problems[0]}</span>
                </div>
            )}

            <div className="grid grid-cols-2 gap-px border-b bg-border @2xl:grid-cols-4">
                <Fact label="Quem trata">
                    {workflow.agent ? (
                        <Link href={`/agents/${workflow.agent.id}`} className="hover:underline">
                            {workflow.agent.name}
                        </Link>
                    ) : (
                        '—'
                    )}
                </Fact>
                <Fact label="Nível">{workflow.agent ? `${workflow.agent.level} · ${workflow.agent.level_label}` : '—'}</Fact>
                <Fact label="Se não conseguir">{workflow.fallback ?? 'A chefia do agente'}</Fact>
                <Fact label="Últimos 30 dias">
                    {workflow.trigger === 'schedule'
                        ? plural(workflow.runs_30d.total, 'vez', 'vezes')
                        : plural(workflow.runs_30d.total, 'email', 'emails')}
                    <span className="font-normal text-muted-foreground"> · {workflow.runs_30d.completed} concluídos</span>
                </Fact>
            </div>

            <div className="flex flex-col gap-2.5 border-b px-4 py-3.5 sm:px-5">
                <div className="flex items-baseline justify-between gap-2">
                    <span className="text-xs font-medium tracking-widest text-muted-foreground uppercase">{plural(total, 'passo', 'passos')}</span>
                    <span className="text-xs text-muted-foreground">Alterado {ago(workflow.updated_at)}</span>
                </div>
                <ReadinessBar summary={workflow.summary} className="h-2" />
                <div className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs @2xl:grid-cols-4">
                    {states.map((state) => (
                        <span key={state} className={cn('inline-flex items-center gap-1.5', workflow.summary[state] === 0 && 'opacity-45')}>
                            <span className={cn('size-2 shrink-0 rounded-full', toneFill[readinessTone[state]])} />
                            <span className="truncate text-muted-foreground">{readinessLabel[state]}</span>
                            <span className="ml-auto font-medium tabular-nums @2xl:ml-0">{workflow.summary[state]}</span>
                        </span>
                    ))}
                </div>
            </div>

            <FlowSteps rows={flatten(workflow.graph)} readiness={workflow.readiness} agents={agents} />

            <div className="border-t px-4 py-3.5 sm:px-5">
                <h3 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                    {workflow.trigger === 'schedule' ? 'Execuções recentes' : 'Emails recentes'}
                </h3>
                {workflow.recent_runs.length === 0 ? (
                    <p className="mt-2 text-sm text-muted-foreground">
                        {workflow.status === 'active'
                            ? workflow.trigger === 'schedule'
                                ? 'Ainda não correu. Começa no próximo horário das rotinas.'
                                : 'Ainda não chegou nenhum email deste tipo desde que o fluxo está activo.'
                            : 'Ainda não correu. Active o fluxo para começar.'}
                    </p>
                ) : (
                    <div className="mt-2 -mx-2 flex flex-col">
                        {workflow.recent_runs.map((run) => (
                            <Link
                                key={run.id}
                                href={run.task ? `/tasks/${run.task.id}` : '#'}
                                className="flex items-center gap-3 rounded-lg px-2 py-1.5 text-sm hover:bg-accent/60"
                            >
                                <StatusDot tone={runTone(run.status)} pulse={run.status === 'running'} />
                                <span className="shrink-0 font-mono text-xs text-muted-foreground">{run.task?.identifier ?? `#${run.id}`}</span>
                                <span className="min-w-0 flex-1 truncate">
                                    {run.node ? `Em «${run.node}»` : (run.task?.title ?? run.status_label)}
                                </span>
                                <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">{run.status_label}</span>
                                {run.started_at && <span className="shrink-0 text-xs text-muted-foreground tabular-nums">{ago(run.started_at)}</span>}
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </div>
    );
}

function Chip({ icon, children, className }: { icon?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <span className={cn('inline-flex items-center gap-1.5 rounded-md border bg-background px-2 py-1', className)}>
            {icon}
            {children}
        </span>
    );
}

function NoFlow({ category, canCreate, onClose }: { category: CategoryRoute; canCreate: boolean; onClose?: () => void }) {
    return (
        <div className={cn('@container flex flex-col', !onClose && 'overflow-hidden rounded-xl border bg-card')}>
            <div className="flex flex-col gap-3 border-b px-4 py-4 sm:px-5">
                <CloseButton onClose={onClose} />
                <div className="flex flex-wrap items-center gap-2">
                    <h2 className="text-base font-semibold tracking-tight">{category.label}</h2>
                    <StatusBadge tone="idle">Sem fluxo</StatusBadge>
                </div>
                <div className="flex flex-wrap items-center gap-1.5 text-xs">
                    <Chip icon={<Mail className="size-3.5 text-muted-foreground" />}>{category.label}</Chip>
                    <ArrowRight className="size-3 text-muted-foreground" />
                    <Chip>A Triagem classifica</Chip>
                    <ArrowRight className="size-3 text-muted-foreground" />
                    <Chip>{category.agent ? `${category.agent.name} abre a tarefa` : 'Avisa a pessoa certa'}</Chip>
                </div>
            </div>

            <div className="grid grid-cols-2 gap-px border-b bg-border">
                <Fact label="Quem trata hoje">
                    {category.agent ? (
                        <Link href={`/agents/${category.agent.id}`} className="hover:underline">
                            {category.agent.name}
                        </Link>
                    ) : (
                        'Ninguém (fica com a Triagem)'
                    )}
                </Fact>
                <Fact label="Pessoa de recurso">{category.fallback ?? '—'}</Fact>
            </div>

            <div className="flex flex-col items-center gap-3 px-6 py-10 text-center">
                <span className="inline-flex size-9 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <WorkflowIcon className="size-4" />
                </span>
                <div className="max-w-md space-y-1">
                    <p className="text-sm font-medium">Este tipo de email não tem passos definidos</p>
                    <p className="text-sm text-muted-foreground">
                        {category.agent
                            ? `O ${category.agent.name} recebe estes emails e começa sozinho, com o que sabe. Um fluxo diz-lhe os passos e o que precisa de uma pessoa.`
                            : 'A Triagem classifica estes emails e avisa a pessoa certa; nenhum agente os trata. Um fluxo dá-os a um agente, com os passos.'}
                    </p>
                </div>
                {canCreate && (
                    <Button asChild>
                        <Link href={`/settings/workflows/new?category=${category.value}`}>
                            <Plus />
                            Criar fluxo para este tipo
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );
}

function AgentsView({
    agents,
    workflows,
    categories,
    onOpen,
}: {
    agents: AgentRow[];
    workflows: WorkflowSummary[];
    categories: CategoryRoute[];
    onOpen: (choice: Choice) => void;
}) {
    const rows = agents
        .map((agent) => ({
            agent,
            leads: workflows.filter((row) => row.agent?.id === agent.id),
            helps: workflows.filter((row) => row.agent?.id !== agent.id && row.graph.nodes.some((node) => node.data.agent_id === agent.id)),
            routes: categories.filter((row) => row.agent?.id === agent.id),
        }))
        .sort((a, b) => b.leads.length + b.helps.length + b.routes.length - (a.leads.length + a.helps.length + a.routes.length));

    if (agents.length === 0) {
        return (
            <div className="mt-5">
                <EmptyState icon={WorkflowIcon} title="Sem agentes" description="Crie um agente para lhe dar tipos de email e fluxos." />
            </div>
        );
    }

    return (
        <div className="mt-5 grid gap-4 md:grid-cols-2">
            {rows.map(({ agent, leads, helps, routes }) => {
                const idle = leads.length === 0 && helps.length === 0 && routes.length === 0;

                return (
                    <div key={agent.id} className={cn('flex flex-col rounded-xl border bg-card', idle && 'bg-transparent')}>
                        <div className="flex items-center gap-3 px-4 py-3">
                            <Monogram name={agent.name} agent />
                            <div className="min-w-0 flex-1">
                                <Link href={`/agents/${agent.id}`} className="block truncate text-sm font-medium hover:underline">
                                    {agent.name}
                                </Link>
                                <div className="truncate text-xs text-muted-foreground">
                                    {agent.level} · {agent.level_label} · {plural(agent.capabilities.length, 'capacidade', 'capacidades')}
                                </div>
                            </div>
                            {!agent.active && <StatusBadge tone="idle">Inactivo</StatusBadge>}
                        </div>
                        {idle ? (
                            <p className="border-t px-4 py-3 text-xs text-muted-foreground">Não recebe emails nem entra em fluxos.</p>
                        ) : (
                            <div className="flex flex-col gap-3 border-t px-4 py-3">
                                {routes.length > 0 && (
                                    <div>
                                        <div className="mb-1.5 text-xs text-muted-foreground">Recebe</div>
                                        <div className="flex flex-wrap gap-1.5">
                                            {routes.map((row) => (
                                                <button
                                                    key={row.value}
                                                    type="button"
                                                    onClick={() => onOpen({ category: row.value })}
                                                    className="inline-flex items-center gap-1.5 rounded-md border bg-background px-2 py-0.5 text-xs hover:bg-accent/60"
                                                >
                                                    <Mail className="size-3 text-muted-foreground" />
                                                    {row.label}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                {leads.length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">Conduz</div>
                                        <div className="-mx-2 flex flex-col">
                                            {leads.map((row) => (
                                                <button
                                                    key={row.id}
                                                    type="button"
                                                    onClick={() => onOpen({ workflow: row.id })}
                                                    className="flex items-center gap-3 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-accent/60"
                                                >
                                                    <span className="min-w-0 flex-1 truncate">{row.name}</span>
                                                    {row.status !== 'active' && (
                                                        <StatusBadge tone={statusTone(row.status)}>{row.status_label}</StatusBadge>
                                                    )}
                                                    <ReadinessBar summary={row.summary} className="w-20 shrink-0" />
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                {helps.length > 0 && (
                                    <div>
                                        <div className="mb-1 text-xs text-muted-foreground">Ajuda em</div>
                                        <div className="flex flex-wrap gap-x-3 gap-y-1">
                                            {helps.map((row) => (
                                                <button
                                                    key={row.id}
                                                    type="button"
                                                    onClick={() => onOpen({ workflow: row.id })}
                                                    className="text-left text-sm hover:underline"
                                                >
                                                    {row.name} <span className="text-xs text-muted-foreground">({row.agent?.name})</span>
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
