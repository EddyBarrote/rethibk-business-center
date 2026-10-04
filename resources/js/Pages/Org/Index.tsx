import { Head, Link, router } from '@inertiajs/react';
import { CircleHelp, ListTodo, Network } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { agentTone, StatusDot } from '@/Components/Status';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

interface OrgAgent {
    id: number;
    name: string;
    title: string | null;
    status: 'active' | 'suspended' | 'draft';
    status_label: string;
    autonomy_level: number;
    department: string | null;
    reports_to_user: string | null;
    reports_to_agent_id: number | null;
    open_tasks: number;
    waiting_tasks: number;
    running: boolean;
}

interface Props {
    agents: OrgAgent[];
    can_manage: boolean;
}

const NONE = 'none';

export default function OrgIndex({ agents, can_manage }: Props) {
    const [errors, setErrors] = useState<Record<number, string>>({});
    const ids = new Set(agents.map((agent) => agent.id));
    const reports = new Map<number | null, OrgAgent[]>();
    agents.forEach((agent) => {
        const manager = agent.reports_to_agent_id !== null && ids.has(agent.reports_to_agent_id) ? agent.reports_to_agent_id : null;
        reports.set(manager, [...(reports.get(manager) ?? []), agent]);
    });
    const roots = reports.get(null) ?? [];

    const below = (id: number): Set<number> => {
        const out = new Set<number>([id]);
        const stack = [id];
        while (stack.length > 0) {
            (reports.get(stack.pop()!) ?? []).forEach((child) => {
                if (!out.has(child.id)) {
                    out.add(child.id);
                    stack.push(child.id);
                }
            });
        }
        return out;
    };

    const move = (agent: OrgAgent, manager: number | null) =>
        router.put(
            `/org/${agent.id}`,
            { reports_to_agent_id: manager },
            {
                preserveScroll: true,
                onSuccess: () => setErrors(({ [agent.id]: _, ...rest }) => rest),
                onError: (bag) => {
                    const message = bag.reports_to_agent_id ?? Object.values(bag)[0] ?? 'Não foi possível mudar o organigrama.';
                    setErrors((current) => ({ ...current, [agent.id]: message }));
                    toast.error(message);
                },
            },
        );

    const running = agents.filter((agent) => agent.running).length;
    const waiting = agents.reduce((sum, agent) => sum + agent.waiting_tasks, 0);

    const renderNode = (agent: OrgAgent, seen: Set<number>, nested = false) => {
        const children = (reports.get(agent.id) ?? []).filter((child) => !seen.has(child.id));
        const nextSeen = new Set([...seen, agent.id]);
        const excluded = can_manage ? below(agent.id) : new Set<number>();

        return (
            <li key={agent.id} className="relative">
                {nested && <span className="absolute top-5 -left-5 h-px w-4 bg-border sm:-left-7 sm:w-6" aria-hidden="true" />}
                <OrgNode
                    agent={agent}
                    error={errors[agent.id]}
                    managerSelect={
                        can_manage ? (
                            <Select
                                value={agent.reports_to_agent_id !== null ? String(agent.reports_to_agent_id) : NONE}
                                onValueChange={(value) => move(agent, value === NONE ? null : Number(value))}
                            >
                                <SelectTrigger size="sm" className="h-7 w-full text-xs sm:w-44" aria-label={`${agent.name} reporta a`}>
                                    <span className="text-muted-foreground">Reporta a</span>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Ninguém (topo)</SelectItem>
                                    {agents
                                        .filter((candidate) => !excluded.has(candidate.id))
                                        .map((candidate) => (
                                            <SelectItem key={candidate.id} value={String(candidate.id)}>
                                                {candidate.name}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                        ) : null
                    }
                />
                {children.length > 0 && (
                    <ul className="relative mt-2 ml-3.5 flex flex-col gap-2 border-l pl-5 sm:ml-5 sm:pl-7">
                        {children.map((child) => renderNode(child, nextSeen, true))}
                    </ul>
                )}
            </li>
        );
    };

    return (
        <AppLayout wide>
            <Head title="Organigrama" />

            <PageHeader
                title="Organigrama"
                description={
                    agents.length > 0
                        ? `${agents.length} agentes · ${running} a trabalhar · ${waiting} ${waiting === 1 ? 'tarefa' : 'tarefas'} à espera de pessoas`
                        : 'Quem reporta a quem entre os agentes, e o que cada um tem em mãos.'
                }
            />

            {agents.length === 0 ? (
                <EmptyState
                    icon={Network}
                    title="Ainda sem agentes"
                    description="Quando a Rethink activar os primeiros agentes, o organigrama mostra quem reporta a quem e o trabalho de cada um."
                />
            ) : (
                <ul className="flex flex-col gap-6">{roots.map((root) => renderNode(root, new Set()))}</ul>
            )}
        </AppLayout>
    );
}

function OrgNode({ agent, managerSelect, error }: { agent: OrgAgent; managerSelect: React.ReactNode; error?: string }) {
    const tone = agentTone(agent.status, agent.running);

    return (
        <div className="flex max-w-3xl flex-col gap-1">
            <div
                className={cn(
                    'flex flex-col gap-3 rounded-xl border bg-card px-3 py-2.5 sm:flex-row sm:items-center',
                    agent.running && 'border-status-running/40',
                    agent.status === 'suspended' && 'opacity-70',
                )}
            >
                <div className="flex min-w-0 flex-1 items-center gap-3">
                    <span className="relative">
                        <AgentAvatar name={agent.name} className="size-9 rounded-lg text-xs" />
                        <span
                            className="absolute -right-0.5 -bottom-0.5 rounded-full bg-card p-0.5"
                            title={agent.running ? 'A trabalhar' : agent.status_label}
                        >
                            <StatusDot tone={tone} />
                        </span>
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex min-w-0 items-center gap-2">
                            <Link href={`/agents/${agent.id}`} className="truncate text-sm font-medium hover:underline">
                                {agent.name}
                            </Link>
                            {agent.running && <span className="text-xs text-status-running">a trabalhar</span>}
                            {agent.status === 'suspended' && <span className="text-xs text-status-danger">{agent.status_label.toLowerCase()}</span>}
                        </div>
                        <p className="truncate text-xs text-muted-foreground">
                            {[agent.title, agent.department].filter(Boolean).join(' · ') || 'Sem função definida'}
                            {agent.reports_to_user && <span> · responde a {agent.reports_to_user}</span>}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:flex-nowrap">
                    <AutonomyBadge level={agent.autonomy_level} />
                    <Link
                        href={`/tasks?view=all&agent=${agent.id}`}
                        className="inline-flex h-5 items-center gap-1 rounded-full bg-muted px-2 text-xs text-muted-foreground hover:text-foreground"
                        title="Tarefas abertas"
                    >
                        <ListTodo className="size-3" />
                        <span className="font-mono tabular-nums">{agent.open_tasks}</span>
                    </Link>
                    {agent.waiting_tasks > 0 && (
                        <Link
                            href={`/tasks?view=waiting&agent=${agent.id}`}
                            className="inline-flex h-5 items-center gap-1 rounded-full bg-status-warning/15 px-2 text-xs text-[color-mix(in_oklch,var(--status-warning)_70%,var(--foreground))]"
                            title="À tua espera"
                        >
                            <CircleHelp className="size-3" />
                            <span className="font-mono tabular-nums">{agent.waiting_tasks}</span>
                        </Link>
                    )}
                    {managerSelect}
                </div>
            </div>
            {error && <InputError message={error} />}
        </div>
    );
}
