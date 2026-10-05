import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, CircleHelp, ListTodo, Maximize2, Minimize2, Network, Pencil } from 'lucide-react';
import { type ReactNode, useLayoutEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { agentTone, StatusDot } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
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
    const [editing, setEditing] = useState(false);
    const [fit, setFit] = useState(true);
    const [collapsed, setCollapsed] = useState<Set<string>>(new Set());
    const toggle = (key: string) =>
        setCollapsed((current) => {
            const next = new Set(current);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });
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

    const managerSelect = (member: Member) => {
        const excluded = below(member.key);

        return (
            <Select value={member.manager ?? NONE} onValueChange={(value) => move(member, value === NONE ? null : value)}>
                <SelectTrigger size="sm" className="h-8 w-full text-xs sm:w-60" aria-label={`${member.name} reporta a`}>
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
        );
    };

    const childrenOf = (member: Member, seen: Set<string>) => (reports.get(member.key) ?? []).filter((child) => !seen.has(child.key));

    /*
     * The chart, top-down: each card sits above the people and agents who
     * report to it. A group made only of leaves hangs under its manager as a
     * column (up to three) or a two-column block, so a manager with five agents
     * does not make the page wide. Any branch folds from its manager's card.
     */
    const renderTree = (member: Member, seen: Set<string>) => {
        const children = childrenOf(member, seen);
        const nextSeen = new Set([...seen, member.key]);
        const leaves = children.length > 0 && children.every((child) => childrenOf(child, nextSeen).length === 0);
        const folded = collapsed.has(member.key);

        return (
            <li key={member.key}>
                <OrgCard member={member} />
                {children.length > 0 && (
                    <button
                        type="button"
                        onClick={() => toggle(member.key)}
                        className="org-fold relative z-10 -mt-px inline-flex h-5 items-center gap-0.5 rounded-b-md border border-t-0 bg-card px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                        aria-expanded={!folded}
                        aria-label={folded ? `Mostrar quem reporta a ${member.name}` : `Esconder quem reporta a ${member.name}`}
                    >
                        {folded ? <ChevronRight className="size-3" /> : <ChevronDown className="size-3" />}
                        {children.length}
                    </button>
                )}
                {!folded &&
                    (leaves ? (
                        <div className={children.length > 3 ? 'org-group' : 'org-leaves'}>
                            {children.map((child) => (
                                <OrgCard key={child.key} member={child} compact />
                            ))}
                        </div>
                    ) : (
                        children.length > 0 && <ul>{children.map((child) => renderTree(child, nextSeen))}</ul>
                    ))}
            </li>
        );
    };

    /** On a phone the same chart reads as an indented list. */
    const renderList = (member: Member, seen: Set<string>, nested = false) => {
        const children = childrenOf(member, seen);
        const nextSeen = new Set([...seen, member.key]);

        return (
            <li key={member.key} className="relative">
                {nested && <span className="absolute top-5 -left-5 h-px w-4 bg-border" aria-hidden="true" />}
                <OrgRow member={member} />
                {children.length > 0 && (
                    <ul className="relative mt-2 ml-3.5 flex flex-col gap-2 border-l pl-5">
                        {children.map((child) => renderList(child, nextSeen, true))}
                    </ul>
                )}
            </li>
        );
    };

    return (
        <AppLayout>
            <Head title="Organigrama" />

            <PageHeader
                title="Organigrama"
                description={
                    members.length > 0
                        ? `${people.length} pessoas e ${agents.length} agentes · ${running} a trabalhar · ${waiting} ${waiting === 1 ? 'tarefa' : 'tarefas'} à espera de pessoas`
                        : 'Quem reporta a quem, pessoas e agentes, e o que cada um tem em mãos.'
                }
                actions={
                    can_manage &&
                    members.length > 0 && (
                        <Button variant="outline" onClick={() => setEditing(true)}>
                            <Pencil />
                            Mudar quem reporta a quem
                        </Button>
                    )
                }
            />

            {members.length === 0 ? (
                <EmptyState
                    icon={Network}
                    title="Ainda sem ninguém"
                    description="Quando houver pessoas e agentes, o organigrama mostra quem reporta a quem."
                />
            ) : (
                <>
                    <div className="relative hidden rounded-xl border bg-muted/20 md:block">
                        <div className="absolute top-2 right-2 z-20 flex gap-1">
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 text-xs"
                                onClick={() => setCollapsed(new Set())}
                                disabled={collapsed.size === 0}
                            >
                                Abrir tudo
                            </Button>
                            <Button variant="outline" size="sm" className="h-7 bg-card text-xs" onClick={() => setFit(!fit)} aria-pressed={fit}>
                                {fit ? <Maximize2 /> : <Minimize2 />}
                                {fit ? 'Tamanho real' : 'Ajustar ao ecrã'}
                            </Button>
                        </div>
                        <FitToWidth enabled={fit}>
                            <div className="org-tree w-fit px-3 pt-12 pb-8">
                                <ul>{roots.map((root) => renderTree(root, new Set()))}</ul>
                            </div>
                        </FitToWidth>
                    </div>
                    <ul className="flex flex-col gap-6 md:hidden">{roots.map((root) => renderList(root, new Set()))}</ul>
                </>
            )}

            <Dialog open={editing} onOpenChange={setEditing}>
                <DialogContent className="flex max-h-[calc(100dvh-2rem)] flex-col gap-0 p-0 sm:max-w-2xl">
                    <DialogHeader className="border-b px-6 pt-6 pb-4">
                        <DialogTitle>Mudar quem reporta a quem</DialogTitle>
                        <DialogDescription>
                            Cada mudança fica guardada logo. Não é possível ficar a reportar a alguém que está abaixo.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-h-0 flex-1 divide-y overflow-y-auto">
                        {[...people, ...agents].map((member) => (
                            <div key={member.key} className="flex flex-col gap-2 px-6 py-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex min-w-0 items-center gap-3">
                                    <MemberAvatar member={member} />
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{member.name}</p>
                                        <p className="truncate text-xs text-muted-foreground">
                                            {member.type === 'agent' ? 'Agente' : 'Pessoa'}
                                            {member.title ? ` · ${member.title}` : ''}
                                        </p>
                                        {errors[member.key] && <InputError message={errors[member.key]} />}
                                    </div>
                                </div>
                                <div className="flex items-center gap-2 sm:shrink-0">
                                    <span className="text-xs text-muted-foreground">Reporta a</span>
                                    {managerSelect(member)}
                                </div>
                            </div>
                        ))}
                    </div>
                    <DialogFooter className="border-t px-6 py-4">
                        <Button onClick={() => setEditing(false)}>Fechar</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

function MemberAvatar({ member, className }: { member: Member; className?: string }) {
    const tone = agentTone(member.status, member.running);

    return (
        <span className="relative shrink-0">
            {member.type === 'agent' ? (
                <AgentAvatar name={member.name} url={member.avatar_url} className={cn('size-9 rounded-lg text-xs', className)} />
            ) : (
                <Monogram name={member.name} className={cn('size-9 rounded-lg text-xs', className)} />
            )}
            <span className="absolute -right-1 -bottom-1 rounded-full bg-card p-0.5" title={member.running ? 'A trabalhar' : member.status_label}>
                <StatusDot tone={tone} />
            </span>
        </span>
    );
}

/** Open tasks and the ones waiting on a person, as two small counters that open the task list. */
function Counters({ member }: { member: Member }) {
    const isAgent = member.type === 'agent';

    return (
        <>
            <Link
                href={isAgent ? `/tasks?view=all&agent=${member.id}` : `/tasks?view=all&person=${member.id}`}
                className="inline-flex h-5 items-center gap-1 rounded-full bg-muted px-2 text-xs text-muted-foreground hover:text-foreground"
                title="Tarefas abertas"
            >
                <ListTodo className="size-3" />
                <span className="tabular-nums">{member.open_tasks}</span>
            </Link>
            {member.waiting_tasks > 0 && (
                <Link
                    href={`/tasks?view=waiting&agent=${member.id}`}
                    className="inline-flex h-5 items-center gap-1 rounded-full bg-status-warning/15 px-2 text-xs text-[color-mix(in_oklch,var(--status-warning)_70%,var(--foreground))]"
                    title="À tua espera"
                >
                    <CircleHelp className="size-3" />
                    <span className="tabular-nums">{member.waiting_tasks}</span>
                </Link>
            )}
        </>
    );
}

/** One card of the chart. Agents open their page; people show their role. */
function OrgCard({ member, compact = false }: { member: Member; compact?: boolean }) {
    const isAgent = member.type === 'agent';
    const name = isAgent ? (
        <Link href={`/agents/${member.id}`} className="line-clamp-2 text-[13px] leading-snug font-medium hover:underline">
            {member.name}
        </Link>
    ) : (
        <span className="line-clamp-2 text-[13px] leading-snug font-medium">{member.name}</span>
    );

    return (
        <div
            className={cn(
                'flex flex-col gap-2 rounded-xl border bg-card px-3 py-2.5 text-left shadow-xs',
                compact ? 'w-48' : 'w-52',
                member.running && 'border-status-running/50',
                member.status === 'suspended' && 'opacity-70',
            )}
        >
            <div className="flex items-start gap-2.5">
                <MemberAvatar member={member} className={cn('text-[10px]', compact ? 'size-7' : 'size-8')} />
                <div className="min-w-0 flex-1">
                    {name}
                    <p className="line-clamp-2 text-[11px] leading-snug text-muted-foreground">
                        {member.title ?? (isAgent ? 'Agente' : 'Sem função definida')}
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
                {isAgent ? (
                    member.autonomy_level !== null && <AutonomyBadge level={member.autonomy_level} />
                ) : (
                    <span className="rounded bg-muted px-1.5 text-[10px] text-muted-foreground">pessoa</span>
                )}
                <Counters member={member} />
            </div>
        </div>
    );
}

function OrgRow({ member }: { member: Member }) {
    const isAgent = member.type === 'agent';

    return (
        <div
            className={cn(
                'flex flex-col gap-2 rounded-xl border bg-card px-3 py-2.5',
                member.running && 'border-status-running/40',
                member.status === 'suspended' && 'opacity-70',
            )}
        >
            <div className="flex min-w-0 items-center gap-3">
                <MemberAvatar member={member} />
                <div className="min-w-0 flex-1">
                    <div className="flex min-w-0 items-center gap-2">
                        {isAgent ? (
                            <Link href={`/agents/${member.id}`} className="truncate text-sm font-medium hover:underline">
                                {member.name}
                            </Link>
                        ) : (
                            <span className="truncate text-sm font-medium">{member.name}</span>
                        )}
                        {!isAgent && <span className="rounded bg-muted px-1.5 text-[10px] text-muted-foreground">pessoa</span>}
                    </div>
                    <p className="truncate text-xs text-muted-foreground">
                        {[member.title, member.department].filter(Boolean).join(' · ') || 'Sem função definida'}
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2">
                {member.autonomy_level !== null && <AutonomyBadge level={member.autonomy_level} />}
                <Counters member={member} />
            </div>
        </div>
    );
}

/**
 * Shrinks the chart to the width of its box (never enlarges it), keeping the
 * box as tall as the shrunk chart. Off, the chart keeps its size and scrolls.
 */
function FitToWidth({ enabled, children }: { enabled: boolean; children: ReactNode }) {
    const outer = useRef<HTMLDivElement>(null);
    const inner = useRef<HTMLDivElement>(null);
    const [size, setSize] = useState({ scale: 1, height: 0 });

    useLayoutEffect(() => {
        const measure = () => {
            if (!outer.current || !inner.current) {
                return;
            }
            const width = inner.current.scrollWidth;
            const scale = enabled ? Math.min(1, outer.current.clientWidth / width) : 1;
            setSize({ scale, height: inner.current.scrollHeight * scale });
        };
        measure();
        const observer = new ResizeObserver(measure);
        if (outer.current) observer.observe(outer.current);
        if (inner.current) observer.observe(inner.current);

        return () => observer.disconnect();
    }, [enabled]);

    return (
        <div
            ref={outer}
            className={cn('w-full', enabled ? 'overflow-hidden' : 'overflow-x-auto')}
            style={enabled ? { height: size.height || undefined } : undefined}
        >
            <div
                ref={inner}
                className="mx-auto w-fit origin-top"
                style={enabled && size.scale < 1 ? { transform: `scale(${size.scale})`, transformOrigin: 'top left', marginLeft: 0 } : undefined}
            >
                {children}
            </div>
        </div>
    );
}
