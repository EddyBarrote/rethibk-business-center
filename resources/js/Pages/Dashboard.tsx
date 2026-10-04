import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Bot, CheckSquare, FileText } from 'lucide-react';

import { ApprovalCard } from '@/Components/ApprovalCard';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EmptyState } from '@/Components/EmptyState';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
import type { AgentSummary, ApprovalSummary, BriefingSummary, Issue, RunSummary, SharedProps } from '@/types';

interface Props {
    approvals: ApprovalSummary[];
    agents: AgentSummary[];
    runs: RunSummary[];
    briefing: BriefingSummary | null;
    issues: Issue[];
}

export default function Dashboard({ approvals, agents, runs, briefing, issues }: Props) {
    const { auth, tenant } = usePage<SharedProps>().props;
    const firstName = auth.user?.name.split(' ')[0];
    const hour = new Date().getHours();
    const greeting = hour < 12 ? 'Bom dia' : hour < 19 ? 'Boa tarde' : 'Boa noite';

    useLive(tenant ? `tenant.${tenant.id}.agents` : null, ['AgentRunStarted', 'AgentRunFinished', 'BudgetThresholdReached'], () =>
        router.reload({ only: ['runs', 'agents', 'approvals', 'auth'] }),
    );

    return (
        <AppLayout>
            <Head title="Painel" />

            <PageHeader title={`${greeting}, ${firstName}`} description="O resumo do dia, as decisões pendentes e o estado dos agentes." />

            <div className="grid gap-6 lg:grid-cols-3">
                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between gap-2">
                            {briefing ? briefing.title : 'Briefing do dia'}
                            {briefing && (
                                <Link href={`/briefings/${briefing.id}`} className="text-sm font-normal text-primary hover:underline">
                                    Abrir
                                </Link>
                            )}
                        </CardTitle>
                        <CardDescription>{briefing ? `${briefing.agent ?? 'Chief of Staff'} · ${dateTime(briefing.created_at)}` : 'Preparado todas as manhãs pelo Chief of Staff.'}</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4">
                        {briefing ? (
                            <div className="grid gap-4">
                                {briefing.decisions_pending.length > 0 && (
                                    <div className="rounded-md border border-amber-300 bg-amber-50 p-3">
                                        <p className="mb-1 text-sm font-medium text-amber-900">Precisa da sua decisão</p>
                                        <ul className="grid gap-1 text-sm">
                                            {briefing.decisions_pending.map((decision, index) => (
                                                <li key={index}>
                                                    {decision.link ? (
                                                        <Link href={decision.link} className="text-amber-900 underline">
                                                            {decision.title}
                                                        </Link>
                                                    ) : (
                                                        decision.title
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                <div className="max-h-96 overflow-y-auto">
                                    <Markdown>{briefing.content ?? ''}</Markdown>
                                </div>
                            </div>
                        ) : (
                            <EmptyState icon={FileText} title="Ainda sem briefings" description="O Chief of Staff prepara o briefing diário às 06:30 dos dias úteis quando estiver activo." />
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center justify-between">
                            Aprovações pendentes
                            {auth.pending_approvals > 0 && <Badge className="bg-amber-500">{auth.pending_approvals}</Badge>}
                        </CardTitle>
                        <CardDescription>Acções dos agentes à espera de decisão.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4 grid gap-3">
                        {approvals.length === 0 ? (
                            <EmptyState icon={CheckSquare} title="Nada à espera" description="Quando um agente tentar uma acção acima do seu nível de autonomia, ela aparece aqui." />
                        ) : (
                            <>
                                {approvals.map((approval) => (
                                    <ApprovalCard key={approval.id} approval={approval} compact />
                                ))}
                                <Link href="/approvals" className="text-sm text-primary hover:underline">
                                    Ver todas
                                </Link>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>

            {issues.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <AlertTriangle className="size-4 text-amber-500" />
                            Bloqueios e inconsistências
                        </CardTitle>
                        <CardDescription>O que está parado ou não bate certo entre áreas.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4">
                        <ul className="grid gap-2">
                            {issues.slice(0, 12).map((issue, index) => (
                                <li key={index} className="flex items-start gap-3 text-sm">
                                    <Badge variant={issue.severity === 'alta' ? 'destructive' : 'secondary'}>{issue.area}</Badge>
                                    {issue.link ? (
                                        <Link href={issue.link} className="hover:underline">
                                            {issue.issue}
                                        </Link>
                                    ) : (
                                        <span>{issue.issue}</span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Agentes</CardTitle>
                        <CardDescription>Estado e nível de autonomia.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4">
                        {agents.length === 0 ? (
                            <EmptyState icon={Bot} title="Nenhum agente activo" description="Os agentes são criados pela Rethink na consola de administração." />
                        ) : (
                            <ul className="grid gap-2">
                                {agents.map((agent) => (
                                    <li key={agent.id}>
                                        <Link href={`/agents/${agent.id}`} className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 hover:bg-muted">
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium">{agent.name}</span>
                                                <span className="block truncate text-xs text-muted-foreground">{agent.title}</span>
                                            </span>
                                            <span className="flex items-center gap-1.5">
                                                {agent.status !== 'active' && <Badge variant="destructive">{agent.status_label}</Badge>}
                                                <AutonomyBadge level={agent.autonomy_level} />
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Actividade recente</CardTitle>
                        <CardDescription>As últimas execuções dos agentes.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4">
                        {runs.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Sem actividade.</p>
                        ) : (
                            <ul className="grid gap-2">
                                {runs.map((run) => (
                                    <li key={run.id}>
                                        <Link href={`/runs/${run.id}`} className="flex items-center justify-between gap-3 rounded-md px-2 py-1.5 hover:bg-muted">
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm">
                                                    <span className="font-medium">{run.agent.name}</span> · {run.input}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">{dateTime(run.created_at)}</span>
                                            </span>
                                            <RunStatusBadge status={run.status} label={run.status_label} />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
