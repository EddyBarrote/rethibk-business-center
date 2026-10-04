import { Head, usePage } from '@inertiajs/react';
import { Bot, CheckSquare } from 'lucide-react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListPanel, Monogram, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { agentTone, StatusBadge } from '@/Components/Status';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, usd } from '@/lib/format';
import type { AgentSummary, SharedProps } from '@/types';

type Row = AgentSummary & { pending_approvals: number; last_run_at: string | null; spent_usd: number };

export default function AgentsIndex({ agents }: { agents: Row[] }) {
    const { sidebar_agents } = usePage<SharedProps>().props;
    const running = new Set(sidebar_agents.filter((agent) => agent.running > 0).map((agent) => agent.id));
    const working = agents.filter((agent) => running.has(agent.id)).length;
    const active = agents.filter((agent) => agent.status === 'active').length;

    return (
        <AppLayout>
            <Head title="Agentes" />
            <PageHeader title="Agentes" description="Os agentes da organização, o estado, o nível de autonomia e a quem respondem." />

            {agents.length === 0 ? (
                <EmptyState
                    icon={Bot}
                    title="Sem agentes"
                    description="Os agentes são criados pela Rethink na consola de administração. Quando estiverem activos, aparecem aqui."
                />
            ) : (
                <Section
                    title={`${agents.length} agentes`}
                    action={
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {active} activos · {working} a trabalhar
                        </span>
                    }
                >
                    <ListPanel>
                        {agents.map((agent) => (
                            <EntityRow
                                key={agent.id}
                                href={`/agents/${agent.id}`}
                                leading={<Monogram name={agent.name} agent className="size-8" />}
                                title={agent.name}
                                subtitle={[agent.title, agent.department].filter(Boolean).join(' · ') || agent.description}
                                meta={
                                    <>
                                        {agent.pending_approvals > 0 && (
                                            <StatusBadge tone="warning" dot={false}>
                                                <CheckSquare className="size-3" />
                                                <span className="tabular-nums">{agent.pending_approvals}</span>
                                            </StatusBadge>
                                        )}
                                        <span className="hidden w-36 truncate lg:block" title={agent.reports_to ?? undefined}>
                                            {agent.reports_to ? `Responde a ${agent.reports_to}` : '—'}
                                        </span>
                                        <span
                                            className="w-20 text-right"
                                            title={agent.last_run_at ? dateTime(agent.last_run_at) : 'Ainda não correu'}
                                        >
                                            {agent.last_run_at ? ago(agent.last_run_at) : 'nunca'}
                                        </span>
                                        <span className="w-20 text-right font-mono tabular-nums" title="Gasto de IA este mês">
                                            {usd(agent.spent_usd)}
                                        </span>
                                    </>
                                }
                                trailing={
                                    <>
                                        <AutonomyBadge level={agent.autonomy_level} />
                                        <span className="flex w-28 justify-end">
                                            {running.has(agent.id) ? (
                                                <StatusBadge tone="running">A trabalhar</StatusBadge>
                                            ) : (
                                                <StatusBadge tone={agentTone(agent.status)} title={agent.suspended_reason ?? undefined}>
                                                    {agent.status_label}
                                                </StatusBadge>
                                            )}
                                        </span>
                                    </>
                                }
                            />
                        ))}
                    </ListPanel>
                </Section>
            )}
        </AppLayout>
    );
}
