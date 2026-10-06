import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Bot, CheckSquare, CircleDollarSign, FileText, Loader } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ApprovalCard, ApprovalList } from '@/Components/ApprovalCard';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListPanel, MetricCard, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { MiniBars } from '@/Components/MiniBars';
import { PageHeader } from '@/Components/PageHeader';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { agentTone, StatusBadge, StatusDot } from '@/Components/Status';
import { useLive } from '@/hooks/useLive';
import { useToolNames } from '@/hooks/useToolNames';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, plainText, runTitle, usd } from '@/lib/format';
import { pathLabel } from '@/lib/paths';
import type { AgentSummary, ApprovalSummary, BriefingSummary, Issue, RunSummary, SharedProps } from '@/types';

interface Metrics {
    agents_active: number;
    agents_suspended: number;
    runs_running: number;
    runs_waiting: number;
    runs_failed_week: number;
    month_spend_usd: number | null;
    month_budget_usd: number | null;
}

interface Day {
    date: string;
    completed: number;
    failed: number;
    waiting: number;
    other: number;
    cost_usd: number;
}

interface Props {
    approvals: ApprovalSummary[];
    agents: AgentSummary[];
    runs: RunSummary[];
    live: RunSummary[];
    metrics: Metrics;
    activity: Day[];
    briefing: BriefingSummary | null;
    issues: Issue[];
    can_view_costs: boolean;
}

function ChartCard({ title, children }: { title: string; children: React.ReactNode }) {
    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-card p-4">
            <div>
                <p className="text-sm font-medium">{title}</p>
                <p className="text-xs text-muted-foreground">Últimos 14 dias</p>
            </div>
            {children}
        </div>
    );
}

export default function Dashboard({ approvals, agents, runs, live, metrics, activity, briefing, issues, can_view_costs }: Props) {
    const toolNames = useToolNames();
    const { auth, tenant, sidebar_agents } = usePage<SharedProps>().props;
    const firstName = auth.user?.name.split(' ')[0];
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Bom dia' : hour < 19 ? 'Boa tarde' : 'Boa noite';
    const running = new Set(sidebar_agents.filter((agent) => agent.running > 0).map((agent) => agent.id));

    useLive(tenant ? `tenant.${tenant.id}.agents` : null, ['AgentRunStarted', 'AgentRunFinished', 'BudgetThresholdReached'], () =>
        router.reload({ only: ['runs', 'live', 'metrics', 'activity', 'agents', 'approvals', 'auth', 'sidebar_agents'] }),
    );

    const finished = activity.reduce((sum, day) => sum + day.completed + day.failed, 0);
    const succeeded = activity.reduce((sum, day) => sum + day.completed, 0);
    const budget = metrics.month_budget_usd;

    // What waits on a decision is counted live above; the briefing's own count is from when it was written.
    const highlights = (briefing?.highlights ?? []).filter((highlight) => !/pendente/i.test(highlight));

    return (
        <AppLayout>
            <Head title="Painel" />

            <PageHeader title={`${greeting}, ${firstName}`} description="O que está a acontecer, o que precisa de si e o estado dos agentes." />

            {live.length > 0 && (
                <Section
                    title="A trabalhar agora"
                    action={
                        <Link href="/runs" className="text-muted-foreground hover:text-foreground">
                            Ver execuções
                        </Link>
                    }
                >
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {live.map((run) => (
                            <Link
                                key={run.id}
                                href={`/runs/${run.id}`}
                                className="flex flex-col gap-3 rounded-xl border bg-card p-4 transition-colors hover:bg-accent/60"
                            >
                                <div className="flex items-center gap-2">
                                    <AgentAvatar name={run.agent.name} />
                                    <span className="min-w-0 flex-1 truncate text-sm font-medium">{run.agent.name}</span>
                                </div>
                                <RunStatusBadge status={run.status} label={run.status_label} />
                                <p className="line-clamp-2 text-sm text-muted-foreground">{run.title ?? runTitle(run.input, toolNames)}</p>
                                <p className="font-mono text-[11px] text-muted-foreground">
                                    #{run.id} · {ago(run.created_at)}
                                </p>
                            </Link>
                        ))}
                    </div>
                </Section>
            )}

            <div className="grid divide-y divide-border rounded-xl border bg-card sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-4 lg:divide-x [&>*]:min-w-0">
                <MetricCard
                    icon={Bot}
                    value={metrics.agents_active}
                    label="Agentes activos"
                    description={`${running.size} a trabalhar · ${metrics.agents_suspended} suspensos`}
                    href="/agents"
                />
                <MetricCard
                    icon={Loader}
                    value={metrics.runs_running}
                    label="Execuções em curso"
                    description={`${metrics.runs_waiting} à espera de aprovação · ${metrics.runs_failed_week} falharam em 7 dias`}
                    href="/runs"
                />
                {can_view_costs && metrics.month_spend_usd !== null && (
                    <MetricCard
                        icon={CircleDollarSign}
                        value={usd(metrics.month_spend_usd)}
                        label="Gasto de IA no mês"
                        description={
                            budget ? `de ${usd(budget)} (${Math.round((metrics.month_spend_usd / budget) * 100)}%)` : 'Sem tecto mensal definido'
                        }
                        tone={
                            budget && metrics.month_spend_usd >= budget
                                ? 'danger'
                                : budget && metrics.month_spend_usd >= budget * 0.8
                                  ? 'warning'
                                  : undefined
                        }
                    />
                )}
                <MetricCard
                    icon={CheckSquare}
                    value={auth.pending_approvals}
                    label="Aprovações pendentes"
                    description="À espera de decisão humana"
                    href="/approvals"
                    tone={auth.pending_approvals > 0 ? 'warning' : undefined}
                />
            </div>

            <div className="grid gap-3 md:grid-cols-3">
                <ChartCard title="Execuções">
                    <MiniBars
                        data={activity}
                        series={[
                            { key: 'completed', label: 'Concluídas', className: 'bg-status-success' },
                            { key: 'waiting', label: 'À espera', className: 'bg-status-warning' },
                            { key: 'failed', label: 'Falharam', className: 'bg-status-danger' },
                            { key: 'other', label: 'Outras', className: 'bg-status-idle' },
                        ]}
                    />
                </ChartCard>
                {can_view_costs && (
                    <ChartCard title="Gasto de IA">
                        <MiniBars
                            data={activity}
                            series={[{ key: 'cost_usd', label: 'Gasto', className: 'bg-primary' }]}
                            format={(value) => usd(value)}
                        />
                    </ChartCard>
                )}
                <ChartCard title="Taxa de sucesso">
                    <div className="flex flex-1 flex-col justify-center gap-2">
                        <span className="text-3xl font-semibold tracking-tight tabular-nums">
                            {finished ? `${Math.round((succeeded / finished) * 100)}%` : '—'}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {finished ? `${succeeded} de ${finished} execuções terminadas acabaram bem` : 'Ainda sem execuções terminadas'}
                        </span>
                        {finished > 0 && (
                            <div className="flex h-1.5 overflow-hidden rounded-full bg-muted">
                                <div className="bg-status-success" style={{ width: `${(succeeded / finished) * 100}%` }} />
                                <div className="bg-status-danger" style={{ width: `${((finished - succeeded) / finished) * 100}%` }} />
                            </div>
                        )}
                    </div>
                </ChartCard>
            </div>

            <div className="grid gap-8 lg:grid-cols-5">
                <Section
                    title="Precisa de si"
                    className="lg:col-span-3"
                    action={
                        approvals.length > 0 && (
                            <Link href="/approvals" className="text-muted-foreground hover:text-foreground">
                                Ver todas
                            </Link>
                        )
                    }
                >
                    {approvals.length === 0 && issues.length === 0 ? (
                        <EmptyState
                            icon={CheckSquare}
                            title="Nada à espera"
                            description="Quando um agente tentar uma acção acima do seu nível de autonomia, ela aparece aqui."
                        />
                    ) : (
                        <div className="grid gap-3">
                            {approvals.length > 0 && (
                                <ApprovalList>
                                    {approvals.slice(0, 3).map((approval) => (
                                        <ApprovalCard key={approval.id} approval={approval} />
                                    ))}
                                    {auth.pending_approvals > 3 && (
                                        <Link
                                            href="/approvals"
                                            className="block px-4 py-2.5 text-center text-sm text-muted-foreground hover:bg-accent/60 hover:text-foreground"
                                        >
                                            Ver as {auth.pending_approvals} aprovações
                                        </Link>
                                    )}
                                </ApprovalList>
                            )}
                            {issues.length > 0 && (
                                <ListPanel>
                                    {issues.slice(0, 8).map((issue, index) => (
                                        <EntityRow
                                            key={index}
                                            href={issue.link && pathLabel(issue.link)?.live ? issue.link : undefined}
                                            leading={
                                                <AlertTriangle
                                                    className={issue.severity === 'alta' ? 'size-4 text-status-danger' : 'size-4 text-status-warning'}
                                                />
                                            }
                                            title={issue.issue}
                                            trailing={<span className="text-xs text-muted-foreground">{issue.area}</span>}
                                        />
                                    ))}
                                </ListPanel>
                            )}
                        </div>
                    )}
                </Section>

                <Section
                    title="Briefing do dia"
                    className="lg:col-span-2"
                    action={
                        briefing && (
                            <Link href={`/briefings/${briefing.id}`} className="text-muted-foreground hover:text-foreground">
                                Abrir
                            </Link>
                        )
                    }
                >
                    {briefing ? (
                        // The headline of the briefing only: what stands out and what waits on a decision, with live
                        // counts. The full text (which repeats the decisions) opens in the briefing itself.
                        <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                            <div>
                                <Link href={`/briefings/${briefing.id}`} className="text-sm font-medium hover:underline">
                                    {briefing.title}
                                </Link>
                                <p className="text-xs text-muted-foreground">
                                    {briefing.agent ?? 'Chief of Staff'} · {dateTime(briefing.created_at)}
                                </p>
                            </div>
                            {briefing.decisions_pending.length > 0 && (
                                <div className="rounded-lg bg-status-warning/10 p-3">
                                    <p className="mb-1.5 text-xs font-medium text-muted-foreground">Precisa da sua decisão</p>
                                    <ul className="grid gap-1.5 text-sm">
                                        {briefing.decisions_pending.map((decision, index) => {
                                            const page = decision.link ? pathLabel(decision.link) : null;

                                            return (
                                                <li key={index} className="flex items-baseline justify-between gap-3">
                                                    <span className="min-w-0">{decision.title}</span>
                                                    {decision.link && page?.live && (
                                                        <Link
                                                            href={decision.link}
                                                            className="shrink-0 text-xs font-medium whitespace-nowrap text-primary hover:underline"
                                                        >
                                                            {decision.link === '/approvals' ? `${auth.pending_approvals} pendentes` : 'Abrir'}
                                                        </Link>
                                                    )}
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            )}
                            {highlights.length > 0 && (
                                <ul className="grid gap-1.5 text-sm text-muted-foreground">
                                    {highlights.slice(0, 4).map((highlight, index) => (
                                        <li key={index} className="flex gap-2">
                                            <span className="mt-2 size-1 shrink-0 rounded-full bg-muted-foreground/60" />
                                            <span className="line-clamp-2">{plainText(highlight)}</span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <Link href={`/briefings/${briefing.id}`} className="border-t pt-3 text-xs font-medium text-primary hover:underline">
                                Ler o briefing completo
                            </Link>
                        </div>
                    ) : (
                        <EmptyState
                            icon={FileText}
                            title="Ainda sem briefings"
                            description="O Chief of Staff prepara o briefing diário às 06:30 dos dias úteis quando estiver activo."
                        />
                    )}
                </Section>
            </div>

            <div className="grid gap-8 lg:grid-cols-2">
                <Section
                    title="Actividade recente"
                    action={
                        <Link href="/runs" className="text-muted-foreground hover:text-foreground">
                            Ver todas
                        </Link>
                    }
                >
                    {runs.length === 0 ? (
                        <EmptyState icon={Loader} title="Sem actividade" description="As execuções dos agentes aparecem aqui assim que começarem." />
                    ) : (
                        <ListPanel>
                            {runs.map((run) => (
                                <EntityRow
                                    key={run.id}
                                    href={`/runs/${run.id}`}
                                    leading={<AgentAvatar name={run.agent.name} />}
                                    title={run.title ?? runTitle(run.input, toolNames)}
                                    subtitle={run.agent.name}
                                    meta={<span title={dateTime(run.created_at)}>{ago(run.created_at)}</span>}
                                    trailing={<RunStatusBadge status={run.status} label={run.status_label} />}
                                />
                            ))}
                        </ListPanel>
                    )}
                </Section>

                <Section
                    title="Agentes"
                    action={
                        <Link href="/agents" className="text-muted-foreground hover:text-foreground">
                            Ver todos
                        </Link>
                    }
                >
                    {agents.length === 0 ? (
                        <EmptyState
                            icon={Bot}
                            title="Nenhum agente activo"
                            description="Crie o primeiro agente em Agentes, ou peça à Rethink que instale os modelos."
                        />
                    ) : (
                        <ListPanel>
                            {agents.map((agent) => (
                                <EntityRow
                                    key={agent.id}
                                    href={`/agents/${agent.id}`}
                                    leading={<AgentAvatar name={agent.name} />}
                                    title={agent.name}
                                    subtitle={agent.title ?? agent.department}
                                    trailing={
                                        <>
                                            {running.has(agent.id) ? (
                                                <StatusBadge tone="running">A trabalhar</StatusBadge>
                                            ) : agent.status !== 'active' ? (
                                                <StatusBadge tone={agentTone(agent.status)}>{agent.status_label}</StatusBadge>
                                            ) : (
                                                <StatusDot tone="success" pulse={false} />
                                            )}
                                            <AutonomyBadge level={agent.autonomy_level} />
                                        </>
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                </Section>
            </div>
        </AppLayout>
    );
}
