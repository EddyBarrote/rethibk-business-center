import { Head, Link } from '@inertiajs/react';
import { Bot, CheckSquare } from 'lucide-react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime, usd } from '@/lib/format';
import type { AgentSummary } from '@/types';

type Row = AgentSummary & { pending_approvals: number; last_run_at: string | null; spent_usd: number };

export default function AgentsIndex({ agents }: { agents: Row[] }) {
    return (
        <AppLayout>
            <Head title="Agentes" />
            <PageHeader title="Agentes" description="Os agentes da organização, o estado, o nível de autonomia e a quem respondem." />

            {agents.length === 0 ? (
                <EmptyState icon={Bot} title="Sem agentes" description="Os agentes são criados pela Rethink na consola de administração. Quando estiverem activos, aparecem aqui." />
            ) : (
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {agents.map((agent) => (
                        <Link key={agent.id} href={`/agents/${agent.id}`} className="group">
                            <Card className="h-full transition-colors group-hover:border-primary/40">
                                <CardHeader>
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="flex size-10 items-center justify-center rounded-lg bg-accent text-accent-foreground">
                                            <Bot className="size-5" />
                                        </div>
                                        <div className="flex gap-1.5">
                                            {agent.status !== 'active' && <Badge variant="destructive">{agent.status_label}</Badge>}
                                            <AutonomyBadge level={agent.autonomy_level} />
                                        </div>
                                    </div>
                                    <CardTitle className="mt-3">{agent.name}</CardTitle>
                                    <CardDescription>{agent.title ?? agent.description}</CardDescription>
                                </CardHeader>
                                <CardContent className="mt-4 grid gap-1 text-xs text-muted-foreground">
                                    {agent.reports_to && <p>Responde a {agent.reports_to}</p>}
                                    <p>Última execução: {dateTime(agent.last_run_at)}</p>
                                    <p>IA este mês: {usd(agent.spent_usd)}</p>
                                    {agent.pending_approvals > 0 && (
                                        <p className="flex items-center gap-1 font-medium text-amber-700">
                                            <CheckSquare className="size-3.5" />
                                            {agent.pending_approvals} aprovação(ões) pendente(s)
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
