import { Head, Link, usePage } from '@inertiajs/react';
import { Bot, CheckSquare, Plus } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListHeader, ListPanel, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { agentTone, StatusBadge } from '@/Components/Status';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, usd } from '@/lib/format';
import type { AgentSummary, SharedProps } from '@/types';

type Row = AgentSummary & { pending_approvals: number; last_run_at: string | null; spent_usd: number };

export default function AgentsIndex({ agents, can }: { agents: Row[]; can: { create: boolean } }) {
    const { sidebar_agents } = usePage<SharedProps>().props;
    const running = new Set(sidebar_agents.filter((agent) => agent.running > 0).map((agent) => agent.id));
    const working = agents.filter((agent) => running.has(agent.id)).length;
    const active = agents.filter((agent) => agent.status === 'active').length;

    return (
        <AppLayout>
            <Head title="Agentes" />
            <PageHeader
                title="Agentes"
                description="Os colegas de IA da organização: o estado, o nível de autonomia e a quem respondem."
                actions={
                    can.create && (
                        <Button asChild>
                            <Link href="/agents/new">
                                <Plus />
                                Novo agente
                            </Link>
                        </Button>
                    )
                }
            />

            {agents.length === 0 ? (
                <EmptyState
                    icon={Bot}
                    title="Sem agentes"
                    description={
                        can.create
                            ? 'Crie o primeiro colega: descreva de quem precisa e a IA propõe a definição.'
                            : 'Os administradores da organização criam os agentes. Quando estiverem activos, aparecem aqui.'
                    }
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
                        <ListHeader
                            leading={<span className="w-8" />}
                            title="Agente"
                            meta={
                                <>
                                    <span className="hidden w-36 lg:block">Responde a</span>
                                    <span className="w-20 text-right">Actividade</span>
                                    <span className="w-20 text-right">IA no mês</span>
                                </>
                            }
                            trailing={
                                <>
                                    <span className="w-24">Autonomia</span>
                                    <span className="w-28 text-right">Estado</span>
                                </>
                            }
                        />
                        {agents.map((agent) => (
                            <EntityRow
                                key={agent.id}
                                href={`/agents/${agent.id}`}
                                leading={<AgentAvatar name={agent.name} url={agent.avatar_url} className="size-8" />}
                                title={
                                    <span className="flex min-w-0 items-center gap-2">
                                        <span className="truncate">{agent.name}</span>
                                        {agent.pending_approvals > 0 && (
                                            <StatusBadge tone="warning" dot={false} title="Aprovações pendentes">
                                                <CheckSquare className="size-3" />
                                                <span className="tabular-nums">{agent.pending_approvals}</span>
                                            </StatusBadge>
                                        )}
                                    </span>
                                }
                                subtitle={[agent.title, agent.department].filter(Boolean).join(' · ') || agent.description}
                                meta={
                                    <>
                                        <span className="hidden w-36 truncate lg:block" title={agent.reports_to ?? undefined}>
                                            {agent.reports_to ?? '—'}
                                        </span>
                                        <span
                                            className="w-20 text-right"
                                            title={agent.last_run_at ? dateTime(agent.last_run_at) : 'Ainda não correu'}
                                        >
                                            {agent.last_run_at ? ago(agent.last_run_at) : 'nunca'}
                                        </span>
                                        <span className="w-20 text-right tabular-nums" title="Gasto de IA este mês">
                                            {usd(agent.spent_usd)}
                                        </span>
                                    </>
                                }
                                trailing={
                                    <>
                                        <span className="hidden w-24 sm:inline-flex">
                                            <AutonomyBadge level={agent.autonomy_level} />
                                        </span>
                                        <span className="flex justify-end sm:w-28">
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
