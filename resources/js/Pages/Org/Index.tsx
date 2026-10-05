import { Head, Link, router } from '@inertiajs/react';
import { CircleHelp, ListTodo, Network } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { agentTone, StatusDot } from '@/Components/Status';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';

interface Member {
    key: string;
    type: 'agent' | 'user';
    id: number;
    name: string;
    avatar_url: string | null;
    title: string | null;
    status: 'active' | 'suspended' | 'draft';
    status_label: string;
    autonomy_level: number | null;
    department: string | null;
    responsible: string | null;
    manager: string | null;
    open_tasks: number;
    waiting_tasks: number;
    running: boolean;
}

interface Props {
    members: Member[];
    can_manage: boolean;
}

const NONE = 'none';

export default function OrgIndex({ members, can_manage }: Props) {
    const [errors, setErrors] = useState<Record<string, string>>({});
    const keys = new Set(members.map((member) => member.key));
    const reports = new Map<string | null, Member[]>();
    members.forEach((member) => {
        const manager = member.manager !== null && keys.has(member.manager) ? member.manager : null;
        reports.set(manager, [...(reports.get(manager) ?? []), member]);
    });
    // People first at the top: the CEO and the board sit above the agents.
    const roots = [...(reports.get(null) ?? [])].sort((a, b) => (a.type === b.type ? 0 : a.type === 'user' ? -1 : 1));

    const below = (key: string): Set<string> => {
        const out = new Set<string>([key]);
        const stack = [key];
        while (stack.length > 0) {
            (reports.get(stack.pop()!) ?? []).forEach((child) => {
                if (!out.has(child.key)) {
                    out.add(child.key);
                    stack.push(child.key);
                }
            });
        }
        return out;
    };

    const move = (member: Member, manager: string | null) =>
        router.put(
            '/org',
            { member: member.key, manager },
            {
                preserveScroll: true,
                onSuccess: () => setErrors(({ [member.key]: _, ...rest }) => rest),
                onError: (bag) => {
                    const message = bag.manager ?? Object.values(bag)[0] ?? 'Não foi possível mudar o organigrama.';
                    setErrors((current) => ({ ...current, [member.key]: message }));
                    toast.error(message);
                },
            },
        );

    const agents = members.filter((member) => member.type === 'agent');
    const people = members.filter((member) => member.type === 'user');
    const running = agents.filter((agent) => agent.running).length;
    const waiting = agents.reduce((sum, agent) => sum + agent.waiting_tasks, 0);

    const renderNode = (member: Member, seen: Set<string>, nested = false) => {
        const children = (reports.get(member.key) ?? []).filter((child) => !seen.has(child.key));
        const nextSeen = new Set([...seen, member.key]);
        const excluded = can_manage ? below(member.key) : new Set<string>();

        return (
            <li key={member.key} className="relative">
                {nested && <span className="absolute top-5 -left-5 h-px w-4 bg-border sm:-left-7 sm:w-6" aria-hidden="true" />}
                <OrgNode
                    member={member}
                    error={errors[member.key]}
                    managerSelect={
                        can_manage ? (
                            <Select value={member.manager ?? NONE} onValueChange={(value) => move(member, value === NONE ? null : value)}>
                                <SelectTrigger size="sm" className="h-7 w-full text-xs sm:w-56" aria-label={`${member.name} reporta a`}>
                                    <span className="shrink-0 text-muted-foreground">Reporta a</span>
                                    {/* The name ends in "…" instead of being cut mid-letter. */}
                                    <span className="min-w-0 flex-1 truncate text-left">
                                        <SelectValue />
                                    </span>
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={NONE}>Ninguém (topo)</SelectItem>
                                    {members
                                        .filter((candidate) => !excluded.has(candidate.key))
                                        .map((candidate) => (
                                            <SelectItem key={candidate.key} value={candidate.key}>
                                                {candidate.name}
                                                {candidate.type === 'agent' ? ' (agente)' : ''}
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
                    members.length > 0
                        ? `${people.length} pessoas e ${agents.length} agentes · ${running} a trabalhar · ${waiting} ${waiting === 1 ? 'tarefa' : 'tarefas'} à espera de pessoas`
                        : 'Quem reporta a quem, pessoas e agentes, e o que cada um tem em mãos.'
                }
            />

            {members.length === 0 ? (
                <EmptyState
                    icon={Network}
                    title="Ainda sem ninguém"
                    description="Quando houver pessoas e agentes, o organigrama mostra quem reporta a quem."
                />
            ) : (
                <ul className="flex flex-col gap-6">{roots.map((root) => renderNode(root, new Set()))}</ul>
            )}
        </AppLayout>
    );
}

function OrgNode({ member: agent, managerSelect, error }: { member: Member; managerSelect: React.ReactNode; error?: string }) {
    const tone = agentTone(agent.status, agent.running);
    const isAgent = agent.type === 'agent';

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
                        {isAgent ? (
                            <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-9 rounded-lg text-xs" />
                        ) : (
                            <Monogram name={agent.name} className="size-9 rounded-lg text-xs" />
                        )}
                        <span
                            className="absolute -right-0.5 -bottom-0.5 rounded-full bg-card p-0.5"
                            title={agent.running ? 'A trabalhar' : agent.status_label}
                        >
                            <StatusDot tone={tone} />
                        </span>
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex min-w-0 items-center gap-2">
                            {isAgent ? (
                                <Link href={`/agents/${agent.id}`} className="truncate text-sm font-medium hover:underline">
                                    {agent.name}
                                </Link>
                            ) : (
                                <span className="truncate text-sm font-medium">{agent.name}</span>
                            )}
                            {!isAgent && <span className="rounded bg-muted px-1.5 text-[10px] text-muted-foreground">pessoa</span>}
                            {agent.running && <span className="text-xs text-status-running">a trabalhar</span>}
                            {agent.status === 'suspended' && <span className="text-xs text-status-danger">{agent.status_label.toLowerCase()}</span>}
                        </div>
                        <p className="truncate text-xs text-muted-foreground">
                            {[agent.title, agent.department].filter(Boolean).join(' · ') || 'Sem função definida'}
                            {agent.responsible && <span> · responde a {agent.responsible}</span>}
                        </p>
                    </div>
                </div>

                <div className="flex flex-wrap items-center gap-2 sm:shrink-0 sm:flex-nowrap">
                    {agent.autonomy_level !== null && <AutonomyBadge level={agent.autonomy_level} />}
                    <Link
                        href={isAgent ? `/tasks?view=all&agent=${agent.id}` : `/tasks?view=all&person=${agent.id}`}
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
