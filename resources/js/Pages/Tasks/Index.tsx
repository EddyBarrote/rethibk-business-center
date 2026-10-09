import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Archive,
    ArrowDown,
    ArrowUp,
    CalendarClock,
    CalendarX2,
    CheckCheck,
    ChevronRight,
    CircleAlert,
    CircleDashed,
    Flag,
    FolderKanban,
    Hand,
    Inbox,
    Layers,
    ListTodo,
    type LucideIcon,
    MessageSquare,
    MessagesSquare,
    Plus,
    Search,
    SearchX,
    SlidersHorizontal,
    UserRound,
    X,
} from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useMemo, useRef, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, deadline, number, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, Paginated, SharedProps } from '@/types';

/* ---------- Shared task vocabulary (also used by Tasks/Show) ---------- */

export type TaskStatus = 'todo' | 'in_progress' | 'waiting_human' | 'in_review' | 'blocked' | 'done' | 'cancelled';
export type TaskPriority = 'low' | 'normal' | 'high' | 'urgent';

export interface TaskSummary {
    id: number;
    ref: string;
    kind: 'task' | 'chat';
    is_conversation: boolean;
    title: string;
    status: TaskStatus;
    status_label: string;
    priority: TaskPriority;
    priority_label: string;
    assignee: { id: number; name: string } | null;
    assignee_user: { id: number; name: string } | null;
    user: string | null;
    user_id: number | null;
    created_by: string | null;
    created_by_agent: boolean;
    goal: { id: number; title: string } | null;
    project: { id: number; name: string } | null;
    messages_count: number | null;
    due_at?: string | null;
    last_activity_at: string | null;
    created_at: string;
    working?: boolean;
}

export interface TaskFormOptions {
    agents: { id: number; name: string; title: string | null }[];
    goals: { id: number; title: string }[];
    projects: { id: number; name: string; goal_id: number | null }[];
    people: { id: number; name: string }[];
    statuses: Option[];
    priorities: Option[];
}

/** Order in which status groups appear: what needs a person first, closed last. */
export const statusOrder: TaskStatus[] = ['waiting_human', 'in_progress', 'in_review', 'blocked', 'todo', 'done', 'cancelled'];

export const taskTone = (status: string): Tone =>
    (
        ({
            todo: 'idle',
            in_progress: 'running',
            waiting_human: 'warning',
            in_review: 'running',
            blocked: 'danger',
            done: 'success',
            cancelled: 'idle',
        }) as Record<string, Tone>
    )[status] ?? 'idle';

const toneText: Record<Tone, string> = {
    running: 'text-status-running',
    success: 'text-status-success',
    warning: 'text-status-warning',
    danger: 'text-status-danger',
    idle: 'text-muted-foreground',
};

/** One glyph shape per task status (Paperclip StatusIcon), coloured by its tone. */
export function TaskStatusIcon({ status, label, className }: { status: string; label?: string; className?: string }) {
    const tone = taskTone(status);

    return (
        <span className={cn('inline-flex size-4 shrink-0', toneText[tone], className)} title={label} role="img" aria-label={label ?? status}>
            <svg viewBox="0 0 16 16" className="size-full" fill="none">
                {status === 'done' ? (
                    <>
                        <circle cx="8" cy="8" r="7" fill="currentColor" />
                        <path d="M5 8.2l2 2 4-4.2" stroke="var(--card)" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" />
                    </>
                ) : (
                    <circle cx="8" cy="8" r="6.25" stroke="currentColor" strokeWidth="1.5" />
                )}
                {status === 'in_progress' && <path d="M8 4a4 4 0 0 1 0 8z" fill="currentColor" />}
                {status === 'in_review' && <path d="M8 4a4 4 0 1 1-4 4h4z" fill="currentColor" />}
                {status === 'waiting_human' && <circle cx="8" cy="8" r="2.5" fill="currentColor" />}
                {status === 'blocked' && <path d="M5 8h6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />}
                {status === 'cancelled' && (
                    <path d="M5.8 5.8l4.4 4.4M10.2 5.8l-4.4 4.4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" />
                )}
            </svg>
        </span>
    );
}

/** Priority marker; normal priority is left blank so the others stand out. */
export function PriorityIcon({ priority, label, withLabel = false }: { priority: string; label?: string; withLabel?: boolean }) {
    const icon =
        priority === 'urgent' ? (
            <CircleAlert className="size-3.5 text-status-danger" />
        ) : priority === 'high' ? (
            <ArrowUp className="size-3.5 text-status-warning" />
        ) : priority === 'low' ? (
            <ArrowDown className="size-3.5 text-muted-foreground" />
        ) : null;

    if (!withLabel) {
        return icon ? <span title={label ? `Prioridade ${label.toLowerCase()}` : undefined}>{icon}</span> : null;
    }

    return (
        <span className="inline-flex items-center gap-1.5">
            {icon ?? <span className="inline-block size-3.5 text-center leading-none text-muted-foreground">–</span>}
            {label}
        </span>
    );
}

/** Small segmented control (two or three options). */
export function Segmented<T extends string>({
    value,
    onChange,
    options,
    className,
}: {
    value: T;
    onChange: (value: T) => void;
    options: { value: T; label: ReactNode; title?: string }[];
    className?: string;
}) {
    return (
        <div role="radiogroup" className={cn('inline-flex h-8 items-center rounded-lg bg-muted p-0.5 text-sm', className)}>
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    title={option.title}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'inline-flex h-7 items-center gap-1.5 rounded-md px-3 font-medium text-muted-foreground transition-colors hover:text-foreground [&_svg]:size-3.5',
                        value === option.value && 'bg-background text-foreground shadow-sm dark:bg-input/40',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

export function ChatBadge() {
    return (
        <span className="inline-flex h-5 shrink-0 items-center gap-1 rounded-full bg-primary/10 px-2 text-[11px] font-medium text-primary">
            <MessagesSquare className="size-3" />
            Conversa
        </span>
    );
}

export function WorkingPulse({ label = 'a trabalhar' }: { label?: string }) {
    return (
        <span className="inline-flex shrink-0 items-center gap-1.5 text-xs font-medium text-status-running">
            <StatusDot tone="running" />
            {label}
        </span>
    );
}

export function GoalChip({ goal }: { goal: { id: number; title: string } }) {
    return (
        <span
            className="inline-flex h-5 max-w-40 items-center gap-1 truncate rounded-md border px-1.5 text-[11px] text-muted-foreground"
            title={goal.title}
        >
            <span className="truncate">{goal.title}</span>
        </span>
    );
}

/* ---------- Page ---------- */

type View = 'mine' | 'waiting' | 'all' | 'chats' | 'closed';
type Group = 'estado' | 'prioridade' | 'responsavel' | 'nenhum';
type Sort = 'actividade' | 'prioridade' | 'prazo' | 'criacao';

interface Filters {
    view: View;
    estado: TaskStatus | null;
    prioridade: TaskPriority | null;
    responsavel: string | null;
    prazo: 'atrasadas' | 'semana' | 'sem-prazo' | null;
    goal: number | null;
    project: number | null;
    q: string;
    ordenar: Sort;
    agrupar: Group;
    por_pagina: number;
}

interface Props extends TaskFormOptions {
    tasks: Paginated<TaskSummary>;
    filters: Filters;
    per_page_options: number[];
    counts: Record<View, number>;
    status_counts: Record<TaskStatus, number>;
    waiting_total: number;
    overdue_total: number;
}

const NONE = 'none';
const live = ['tasks', 'counts', 'status_counts', 'waiting_total', 'overdue_total'];

const views: { key: View; label: string; icon: LucideIcon; hint: string }[] = [
    { key: 'mine', label: 'Minhas', icon: Inbox, hint: 'Abertas consigo: suas, pedidas por si ou com uma aprovação sua' },
    { key: 'waiting', label: 'À sua espera', icon: Hand, hint: 'Um agente espera pela sua resposta' },
    { key: 'all', label: 'Todas abertas', icon: Layers, hint: 'Todo o trabalho aberto que pode ver' },
    { key: 'closed', label: 'Fechadas', icon: Archive, hint: 'Feitas ou canceladas' },
];

const dueLabels: Record<NonNullable<Filters['prazo']>, string> = {
    atrasadas: 'Prazo ultrapassado',
    semana: 'Prazo nos próximos 7 dias',
    'sem-prazo': 'Sem prazo',
};
const groupLabels: Record<Group, string> = { estado: 'Estado', prioridade: 'Prioridade', responsavel: 'Responsável', nenhum: 'Sem grupos' };
const sortLabels: Record<Sort, string> = {
    actividade: 'Actividade recente',
    prioridade: 'Prioridade',
    prazo: 'Prazo mais próximo',
    criacao: 'Mais recentes',
};
const priorityOrder: TaskPriority[] = ['urgent', 'high', 'normal', 'low'];

const emptyCopy: Record<View, { title: string; description: string }> = {
    mine: {
        title: 'Nada consigo',
        description: 'Quando lhe atribuírem trabalho, pedir algo a um agente ou uma aprovação esperar por si, aparece aqui.',
    },
    waiting: { title: 'Nenhum agente à sua espera', description: 'Quando um agente precisar de uma resposta sua, a tarefa aparece aqui.' },
    all: { title: 'Sem tarefas abertas', description: 'Crie a primeira tarefa e atribua-a a um agente ou a uma pessoa.' },
    chats: { title: 'Sem conversas', description: 'Comece uma conversa com um agente para lhe pedir ajuda como assistente.' },
    closed: { title: 'Nada fechado', description: 'As tarefas feitas ou canceladas aparecem aqui.' },
};

export default function TasksIndex({
    tasks,
    filters,
    per_page_options,
    counts,
    status_counts,
    waiting_total,
    overdue_total,
    agents,
    people,
    goals,
    projects,
    priorities,
    statuses,
}: Props) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const canCreate = auth.user?.is_manager ?? false;
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<number[]>([]);
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});

    useLive(tenant ? `tenant.${tenant.id}.tasks` : null, ['TaskUpdated'], () => router.reload({ only: live }), {
        only: live,
        poll: true,
        intervalMs: 10000,
    });

    // A new page of results is a new selection.
    useEffect(
        () => setSelected([]),
        [filters.view, filters.estado, filters.prioridade, filters.responsavel, filters.prazo, filters.q, tasks.current_page],
    );

    // A changed filter starts again at page 1; defaults stay out of the address.
    const go = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const params: Record<string, string | number> = {};
        if (merged.view !== 'mine') params.view = merged.view;
        if (merged.estado) params.estado = merged.estado;
        if (merged.prioridade) params.prioridade = merged.prioridade;
        if (merged.responsavel) params.responsavel = merged.responsavel;
        if (merged.prazo) params.prazo = merged.prazo;
        if (merged.goal) params.goal = merged.goal;
        if (merged.project) params.project = merged.project;
        if (merged.q) params.q = merged.q;
        if (merged.ordenar !== 'actividade') params.ordenar = merged.ordenar;
        if (merged.agrupar !== 'estado') params.agrupar = merged.agrupar;
        if (merged.por_pagina !== per_page_options[0]) params.por_pagina = merged.por_pagina;
        router.get('/tasks', params, { preserveState: true, preserveScroll: true, replace: next.q !== undefined });
    };

    // Searches as the person types, a moment after they stop.
    const [query, setQuery] = useState(filters.q);
    const typed = useRef(false);
    useEffect(() => {
        if (!typed.current) {
            return;
        }
        const timer = window.setTimeout(() => go({ q: query.trim() }), 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);
    const search = (value: string) => {
        typed.current = true;
        setQuery(value);
    };

    const assigneeName = (key: string | null) => {
        if (!key) return null;
        if (key === 'ninguem') return 'Por atribuir';
        const [type, id] = key.split(':');
        const list: { id: number; name: string }[] = type === 'agent' ? agents : people;

        return list.find((item) => item.id === Number(id))?.name ?? (type === 'agent' ? 'Agente' : 'Pessoa');
    };
    const statusLabel = (status: string) => statuses.find((s) => s.value === status)?.label ?? status;
    const priorityLabel = (priority: string) => priorities.find((p) => p.value === priority)?.label ?? priority;

    const chips = [
        filters.estado && { key: 'estado', label: `Estado: ${statusLabel(filters.estado)}`, clear: { estado: null } },
        filters.responsavel && { key: 'responsavel', label: `Responsável: ${assigneeName(filters.responsavel)}`, clear: { responsavel: null } },
        filters.prioridade && { key: 'prioridade', label: `Prioridade: ${priorityLabel(filters.prioridade)}`, clear: { prioridade: null } },
        filters.prazo && { key: 'prazo', label: dueLabels[filters.prazo], clear: { prazo: null } },
        filters.project && {
            key: 'project',
            label: `Projecto: ${projects.find((p) => p.id === filters.project)?.name ?? '—'}`,
            clear: { project: null },
        },
        filters.goal && { key: 'goal', label: `Objectivo: ${goals.find((g) => g.id === filters.goal)?.title ?? '—'}`, clear: { goal: null } },
    ].filter(Boolean) as { key: string; label: string; clear: Partial<Filters> }[];
    const filtered = chips.length > 0 || filters.q !== '';

    const groups = useMemo(() => {
        const keyOf = (task: TaskSummary): { key: string; label: ReactNode; icon: ReactNode } => {
            if (filters.agrupar === 'estado') {
                return { key: task.status, label: task.status_label, icon: <TaskStatusIcon status={task.status} /> };
            }
            if (filters.agrupar === 'prioridade') {
                return { key: task.priority, label: task.priority_label, icon: <PriorityIcon priority={task.priority} /> };
            }
            if (filters.agrupar === 'responsavel') {
                const who = task.assignee ?? task.assignee_user;
                return who
                    ? {
                          key: `${task.assignee ? 'agent' : 'user'}:${who.id}`,
                          label: who.name,
                          icon: task.assignee ? (
                              <AgentAvatar name={who.name} className="size-5 rounded-md text-[9px]" />
                          ) : (
                              <Monogram name={who.name} className="size-5 rounded-md text-[9px]" />
                          ),
                      }
                    : { key: 'ninguem', label: 'Por atribuir', icon: <UserRound className="size-4 text-muted-foreground" /> };
            }

            return { key: 'all', label: '', icon: null };
        };
        const list: { key: string; label: ReactNode; icon: ReactNode; items: TaskSummary[] }[] = [];
        tasks.data.forEach((task) => {
            const group = keyOf(task);
            const last = list[list.length - 1];
            if (last?.key === group.key) {
                last.items.push(task);
            } else {
                list.push({ ...group, items: [task] });
            }
        });

        return list;
    }, [tasks.data, filters.agrupar]);

    const pageIds = tasks.data.map((task) => task.id);
    const toggle = (id: number, on: boolean) => setSelected((ids) => (on ? [...ids, id] : ids.filter((other) => other !== id)));
    const bulk = (data: { status?: string; priority?: string }) =>
        router.post('/tasks/bulk', { ids: selected, ...data }, { preserveScroll: true, onSuccess: () => setSelected([]) });
    const setStatus = (task: TaskSummary, status: string) => router.patch(`/tasks/${task.id}`, { status }, { preserveScroll: true, only: live });
    const inViewStatuses = statusOrder.filter((status) => status_counts[status] > 0 || filters.estado === status);

    return (
        <AppLayout>
            <Head title={filters.view === 'chats' ? 'Conversas' : 'Tarefas'} />

            <PageHeader
                title={filters.view === 'chats' ? 'Conversas' : 'Tarefas'}
                description={
                    filters.view === 'chats'
                        ? 'As suas conversas com os agentes: uma por agente, sempre a mesma, com o que lhe pediu e o que ele respondeu.'
                        : 'O trabalho das pessoas e dos agentes: o que está em curso, o que espera por si e o que já ficou feito.'
                }
                actions={
                    canCreate && (
                        <Button size="sm" onClick={() => setOpen(true)}>
                            <Plus />
                            Nova tarefa
                        </Button>
                    )
                }
            />

            {(waiting_total > 0 && filters.view !== 'waiting') || (overdue_total > 0 && filters.prazo !== 'atrasadas') ? (
                <div className="flex flex-col gap-2 sm:flex-row">
                    {waiting_total > 0 && filters.view !== 'waiting' && (
                        <Strip
                            tone="warning"
                            icon={Hand}
                            text={`${plural(waiting_total, 'tarefa espera', 'tarefas esperam')} pela sua resposta.`}
                            action="Responder"
                            onClick={() => go({ view: 'waiting', estado: null })}
                        />
                    )}
                    {overdue_total > 0 && filters.prazo !== 'atrasadas' && (
                        <Strip
                            tone="danger"
                            icon={CalendarX2}
                            text={`${plural(overdue_total, 'tarefa sua passou', 'tarefas suas passaram')} do prazo.`}
                            action="Ver"
                            onClick={() => go({ view: 'mine', prazo: 'atrasadas', estado: null })}
                        />
                    )}
                </div>
            ) : null}

            <div className="grid grid-cols-[minmax(0,1fr)] gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
                {/* Views and statuses: a rail on wide screens, a scrolling row of chips on a phone. */}
                <nav aria-label="Vistas de tarefas" className="flex min-w-0 flex-col gap-4 max-lg:-mx-4">
                    <div className="scroll-fade flex gap-1 overflow-x-auto max-lg:px-4 lg:flex-col lg:overflow-visible">
                        {views.map((view) => (
                            <RailButton
                                key={view.key}
                                label={view.label}
                                title={view.hint}
                                icon={<view.icon className="size-4 shrink-0" />}
                                active={filters.view === view.key}
                                count={counts[view.key]}
                                urgent={view.key === 'waiting' && counts.waiting > 0}
                                onClick={() => go({ view: view.key, estado: null })}
                            />
                        ))}
                    </div>
                    {filters.view !== 'chats' && inViewStatuses.length > 1 && (
                        <div className="hidden flex-col gap-1 lg:flex">
                            <div className="px-2 pb-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Por estado</div>
                            {inViewStatuses.map((status) => (
                                <RailButton
                                    key={status}
                                    label={statusLabel(status)}
                                    icon={<TaskStatusIcon status={status} />}
                                    active={filters.estado === status}
                                    count={status_counts[status]}
                                    onClick={() => go({ estado: filters.estado === status ? null : status })}
                                />
                            ))}
                        </div>
                    )}
                </nav>

                <div className="@container flex min-w-0 flex-col gap-4">
                    <div className="flex flex-col gap-2 @3xl:flex-row @3xl:items-center">
                        <div className="relative w-full @3xl:w-64 @5xl:w-72">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) => search(event.target.value)}
                                onKeyDown={(event) => event.key === 'Escape' && query !== '' && search('')}
                                placeholder="Pesquisar título, descrição, nº ou responsável"
                                aria-label="Pesquisar tarefas"
                                className="h-8 pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden"
                            />
                            {query !== '' && (
                                <button
                                    type="button"
                                    onClick={() => search('')}
                                    className="absolute top-1/2 right-2 -translate-y-1/2 rounded text-muted-foreground hover:text-foreground"
                                    aria-label="Limpar pesquisa"
                                >
                                    <X className="size-4" />
                                </button>
                            )}
                        </div>
                        <div className="grid grid-cols-2 gap-2 @xl:grid-cols-4 @3xl:flex @3xl:flex-1 @3xl:items-center">
                            <FilterSelect
                                label="Responsável"
                                value={filters.responsavel}
                                onChange={(responsavel) => go({ responsavel })}
                                className="@3xl:w-40"
                                options={[
                                    { value: 'ninguem', label: 'Por atribuir' },
                                    ...agents.map((agent) => ({ value: `agent:${agent.id}`, label: agent.name, hint: 'agente' })),
                                    ...people.map((person) => ({ value: `user:${person.id}`, label: person.name, hint: 'pessoa' })),
                                ]}
                            />
                            <FilterSelect
                                label="Prioridade"
                                value={filters.prioridade}
                                onChange={(prioridade) => go({ prioridade: prioridade as TaskPriority | null })}
                                className="@3xl:w-32"
                                options={priorityOrder.map((p) => ({
                                    value: p,
                                    label: <PriorityIcon priority={p} label={priorityLabel(p)} withLabel />,
                                }))}
                            />
                            <FilterSelect
                                label="Prazo"
                                value={filters.prazo}
                                onChange={(prazo) => go({ prazo: prazo as Filters['prazo'] })}
                                className="@3xl:w-32"
                                options={Object.entries(dueLabels).map(([value, label]) => ({ value, label }))}
                            />
                            {filters.view !== 'chats' && (
                                <FilterSelect
                                    label="Estado"
                                    value={filters.estado}
                                    onChange={(estado) => go({ estado: estado as TaskStatus | null })}
                                    className="@3xl:w-32 lg:hidden"
                                    options={inViewStatuses.map((s) => ({ value: s, label: statusLabel(s) }))}
                                />
                            )}
                        </div>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="outline" size="sm" className="@3xl:ml-auto">
                                    <SlidersHorizontal />
                                    Mostrar
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuLabel>Agrupar por</DropdownMenuLabel>
                                <DropdownMenuRadioGroup value={filters.agrupar} onValueChange={(agrupar) => go({ agrupar: agrupar as Group })}>
                                    {(Object.keys(groupLabels) as Group[]).map((key) => (
                                        <DropdownMenuRadioItem key={key} value={key}>
                                            {groupLabels[key]}
                                        </DropdownMenuRadioItem>
                                    ))}
                                </DropdownMenuRadioGroup>
                                <DropdownMenuSeparator />
                                <DropdownMenuLabel>Ordenar por</DropdownMenuLabel>
                                <DropdownMenuRadioGroup value={filters.ordenar} onValueChange={(ordenar) => go({ ordenar: ordenar as Sort })}>
                                    {(Object.keys(sortLabels) as Sort[]).map((key) => (
                                        <DropdownMenuRadioItem key={key} value={key}>
                                            {sortLabels[key]}
                                        </DropdownMenuRadioItem>
                                    ))}
                                </DropdownMenuRadioGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>

                    {chips.length > 0 && (
                        <div className="flex flex-wrap items-center gap-1.5">
                            {chips.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={() => go(chip.clear)}
                                    className="inline-flex h-7 max-w-full items-center gap-1.5 rounded-full border bg-card px-2.5 text-xs text-foreground/80 hover:bg-accent"
                                    title="Retirar filtro"
                                >
                                    <span className="truncate">{chip.label}</span>
                                    <X className="size-3 shrink-0 text-muted-foreground" />
                                </button>
                            ))}
                            <Button
                                variant="ghost"
                                size="sm"
                                className="h-7 text-xs"
                                onClick={() => {
                                    typed.current = false;
                                    setQuery('');
                                    go({ estado: null, responsavel: null, prioridade: null, prazo: null, project: null, goal: null, q: '' });
                                }}
                            >
                                Limpar filtros
                            </Button>
                        </div>
                    )}

                    {tasks.data.length === 0 ? (
                        <EmptyState
                            icon={filtered ? SearchX : filters.view === 'chats' ? MessagesSquare : filters.view === 'waiting' ? CheckCheck : ListTodo}
                            title={filtered ? 'Nada encontrado' : emptyCopy[filters.view].title}
                            description={
                                filtered
                                    ? `Nenhuma tarefa em «${views.find((v) => v.key === filters.view)?.label}»${filters.q ? ` tem «${filters.q}»` : ' corresponde aos filtros'}. Experimente outra palavra, retire filtros ou mude de vista.`
                                    : emptyCopy[filters.view].description
                            }
                            action={
                                canCreate &&
                                !filtered &&
                                filters.view !== 'chats' && (
                                    <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                                        <Plus />
                                        Nova tarefa
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <>
                            {/* Sticky under the top bar while choosing down the list. */}
                            <div className="sticky top-14 z-20 flex min-h-11 flex-wrap items-center gap-3 rounded-xl border bg-card px-4 py-1.5 text-sm shadow-xs">
                                <Checkbox
                                    checked={selected.length === 0 ? false : selected.length === pageIds.length ? true : 'indeterminate'}
                                    onCheckedChange={(on) => setSelected(on === true ? pageIds : [])}
                                    aria-label="Seleccionar todas desta página"
                                />
                                <span className="text-muted-foreground">
                                    {selected.length === 0
                                        ? 'Seleccione para mudar várias de uma vez'
                                        : plural(selected.length, 'seleccionada', 'seleccionadas')}
                                </span>
                                {selected.length > 0 && (
                                    <div className="ml-auto flex flex-wrap items-center gap-1.5">
                                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                                            Limpar
                                        </Button>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button size="sm" variant="outline">
                                                    <Flag />
                                                    Prioridade
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {priorityOrder.map((p) => (
                                                    <DropdownMenuItem key={p} onSelect={() => bulk({ priority: p })}>
                                                        <PriorityIcon priority={p} label={priorityLabel(p)} withLabel />
                                                    </DropdownMenuItem>
                                                ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button size="sm">
                                                    <CircleDashed />
                                                    Mudar estado
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end">
                                                {statusOrder.map((s) => (
                                                    <DropdownMenuItem key={s} onSelect={() => bulk({ status: s })}>
                                                        <TaskStatusIcon status={s} />
                                                        {statusLabel(s)}
                                                    </DropdownMenuItem>
                                                ))}
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                )}
                            </div>

                            {groups.map((group, index) => {
                                const key = `${group.key}-${index}`;
                                const isCollapsed = collapsed[group.key] ?? false;
                                const total = filters.agrupar === 'estado' ? status_counts[group.key as TaskStatus] : undefined;

                                return (
                                    <section key={key} className="flex flex-col gap-1.5">
                                        {filters.agrupar !== 'nenhum' && (
                                            <button
                                                type="button"
                                                onClick={() => setCollapsed({ ...collapsed, [group.key]: !isCollapsed })}
                                                className="flex w-fit items-center gap-2 rounded-md px-1 py-0.5 text-sm font-medium hover:bg-accent/60"
                                                aria-expanded={!isCollapsed}
                                            >
                                                <ChevronRight
                                                    className={cn('size-3.5 text-muted-foreground transition-transform', !isCollapsed && 'rotate-90')}
                                                />
                                                {group.icon}
                                                {group.label}
                                                <span className="font-mono text-xs text-muted-foreground tabular-nums">
                                                    {total !== undefined && total > group.items.length
                                                        ? `${group.items.length} de ${number(total)}`
                                                        : group.items.length}
                                                </span>
                                            </button>
                                        )}
                                        {!isCollapsed && (
                                            <div className="divide-y overflow-hidden rounded-xl border bg-card">
                                                {group.items.map((task) => (
                                                    <TaskRow
                                                        key={task.id}
                                                        task={task}
                                                        selectable={{ selected: selected.includes(task.id), onSelect: (on) => toggle(task.id, on) }}
                                                        onStatus={task.kind === 'task' ? (status) => setStatus(task, status) : undefined}
                                                        statuses={statuses}
                                                    />
                                                ))}
                                            </div>
                                        )}
                                    </section>
                                );
                            })}
                        </>
                    )}

                    {tasks.total > 0 && (
                        <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center">
                            <Pagination page={tasks} noun={['tarefa', 'tarefas']} className="flex-1" />
                            <label className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                                Por página
                                <NativeSelect
                                    value={filters.por_pagina}
                                    onChange={(event) => go({ por_pagina: Number(event.target.value) })}
                                    className="h-8 w-20 text-xs md:text-xs"
                                    aria-label="Tarefas por página"
                                >
                                    {per_page_options.map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </label>
                        </div>
                    )}
                </div>
            </div>

            <NewTaskDialog
                open={open}
                onOpenChange={setOpen}
                agents={agents}
                people={people}
                goals={goals}
                projects={projects}
                priorities={priorities}
                defaultAgent={filters.responsavel?.startsWith('agent:') ? Number(filters.responsavel.slice(6)) : null}
                defaultProject={filters.project}
            />
        </AppLayout>
    );
}

function Strip({
    tone,
    icon: Icon,
    text,
    action,
    onClick,
}: {
    tone: 'warning' | 'danger';
    icon: LucideIcon;
    text: string;
    action: string;
    onClick: () => void;
}) {
    return (
        <div
            className={cn(
                'flex flex-1 items-center gap-3 rounded-xl border px-4 py-2.5 text-sm',
                tone === 'warning' ? 'border-status-warning/40 bg-status-warning/10' : 'border-status-danger/30 bg-status-danger/[0.07]',
            )}
        >
            <span
                className={cn(
                    'inline-flex size-7 shrink-0 items-center justify-center rounded-lg',
                    tone === 'warning' ? 'bg-status-warning/20' : 'bg-status-danger/15 text-status-danger',
                )}
            >
                <Icon className="size-4" />
            </span>
            <span className="min-w-0 flex-1 font-medium">{text}</span>
            <Button size="sm" variant="outline" onClick={onClick}>
                {action}
            </Button>
        </div>
    );
}

function RailButton({
    label,
    title,
    icon,
    active,
    count,
    urgent = false,
    onClick,
}: {
    label: string;
    title?: string;
    icon: ReactNode;
    active: boolean;
    count: number;
    urgent?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={title}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex h-8 shrink-0 items-center gap-2 rounded-lg px-2.5 text-sm whitespace-nowrap transition-colors max-lg:border',
                active ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
            )}
        >
            {icon}
            <span className="truncate lg:flex-1 lg:text-left">{label}</span>
            {count > 0 && (
                <span
                    className={cn(
                        'rounded-full px-1.5 font-mono text-[11px] text-foreground tabular-nums',
                        urgent ? 'bg-status-warning/20' : active ? 'bg-background/80' : 'bg-muted',
                    )}
                >
                    {number(count)}
                </span>
            )}
        </button>
    );
}

function FilterSelect({
    label,
    value,
    onChange,
    options,
    className,
}: {
    label: string;
    value: string | null;
    onChange: (value: string | null) => void;
    options: { value: string; label: ReactNode; hint?: string }[];
    className?: string;
}) {
    return (
        <Select value={value ?? NONE} onValueChange={(next) => onChange(next === NONE ? null : next)}>
            <SelectTrigger size="sm" className={cn('w-full', value && 'border-primary/40 bg-primary/5', className)} aria-label={label}>
                <SelectValue placeholder={label}>{value ? (options.find((option) => option.value === value)?.label ?? label) : label}</SelectValue>
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={NONE}>
                    <span className="text-muted-foreground">{label}: todos</span>
                </SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                        {option.hint && <span className="text-muted-foreground"> · {option.hint}</span>}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** A due date as a small chip: red when it has passed, amber when it is within two days. */
export function DueChip({ due, closed }: { due: string; closed: boolean }) {
    const left = new Date(due).getTime() - Date.now();
    const tone = closed ? 'idle' : left < 0 ? 'danger' : left < 2 * 86_400_000 ? 'warning' : 'idle';

    return (
        <span
            className={cn(
                'inline-flex h-5 shrink-0 items-center gap-1 rounded-md border px-1.5 text-[11px] whitespace-nowrap tabular-nums',
                tone === 'danger' && 'border-status-danger/40 bg-status-danger/10 text-status-danger',
                tone === 'warning' && 'border-status-warning/40 bg-status-warning/10 text-foreground',
                tone === 'idle' && 'text-muted-foreground',
            )}
            title={`Prazo: ${deadline(due)}`}
        >
            <CalendarClock className="size-3" />
            {tone === 'danger' ? `Atrasada ${ago(due)}` : shortDay(due)}
        </span>
    );
}

const shortDayFormat = new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'short' });
const shortDay = (value: string) => shortDayFormat.format(new Date(value)).replace('.', '');

export function TaskRow({
    task,
    compact = false,
    selectable,
    onStatus,
    statuses,
}: {
    task: TaskSummary;
    compact?: boolean;
    selectable?: { selected: boolean; onSelect: (on: boolean) => void };
    onStatus?: (status: string) => void;
    statuses?: Option[];
}) {
    const closed = task.status === 'done' || task.status === 'cancelled';
    const who = task.assignee ?? task.assignee_user;

    // Sized by the room the list has, not the window: the same row sits in a page, a tab and a side column.
    return (
        <div className="@container">
            <div
                className={cn(
                    'group relative flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-accent/50',
                    selectable?.selected && 'bg-accent/70',
                )}
            >
                {selectable && (
                    <span className="relative z-10 flex items-center">
                        <Checkbox
                            checked={selectable.selected}
                            onCheckedChange={(on) => selectable.onSelect(on === true)}
                            aria-label={`Seleccionar «${task.title}»`}
                        />
                    </span>
                )}
                {onStatus && statuses ? (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="relative z-10 -m-1 rounded-md p-1 hover:bg-accent"
                                aria-label={`Estado: ${task.status_label}. Mudar`}
                            >
                                <TaskStatusIcon status={task.status} label={task.status_label} />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start">
                            <DropdownMenuLabel>Mudar estado</DropdownMenuLabel>
                            <DropdownMenuRadioGroup value={task.status} onValueChange={onStatus}>
                                {statusOrder.map((status) => (
                                    <DropdownMenuRadioItem key={status} value={status}>
                                        <TaskStatusIcon status={status} />
                                        {statuses.find((s) => s.value === status)?.label ?? status}
                                    </DropdownMenuRadioItem>
                                ))}
                            </DropdownMenuRadioGroup>
                        </DropdownMenuContent>
                    </DropdownMenu>
                ) : (
                    <TaskStatusIcon status={task.status} label={task.status_label} />
                )}
                <span className="hidden w-16 shrink-0 font-mono text-xs text-muted-foreground @lg:inline">{task.ref}</span>
                <div className="min-w-0 flex-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <PriorityIcon priority={task.priority} label={task.priority_label} />
                        <Link
                            href={`/tasks/${task.id}`}
                            className={cn(
                                'line-clamp-2 min-w-0 text-sm font-medium break-words after:absolute after:inset-0 focus-visible:outline-none @lg:line-clamp-1',
                                closed && 'text-muted-foreground',
                            )}
                        >
                            {task.title}
                        </Link>
                        {task.kind === 'chat' && (
                            <span className="hidden shrink-0 @lg:inline-flex">
                                <ChatBadge />
                            </span>
                        )}
                        {task.working && <WorkingPulse />}
                    </div>
                    {/* Wraps to a second line on a phone instead of cutting every name. */}
                    <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span className="shrink-0 font-mono whitespace-nowrap @lg:hidden">{task.ref}</span>
                        {who && <span className={cn('max-w-full truncate', !compact && '@3xl:hidden')}>{who.name}</span>}
                        {task.created_by_agent && task.created_by && <span className="max-w-full truncate">delegada por {task.created_by}</span>}
                        {!task.created_by_agent && task.user && !compact && (
                            <span className="hidden truncate @4xl:inline">pedida por {task.user}</span>
                        )}
                        {task.due_at && (
                            <span className={cn(!compact && '@3xl:hidden')}>
                                <DueChip due={task.due_at} closed={closed} />
                            </span>
                        )}
                    </div>
                </div>
                {!compact && (
                    <div className="hidden shrink-0 items-center gap-2 text-xs text-muted-foreground @3xl:flex">
                        {task.project ? (
                            <span
                                className="hidden max-w-40 items-center gap-1 truncate rounded-md border px-1.5 py-0.5 text-[11px] @5xl:inline-flex"
                                title={`Projecto: ${task.project.name}`}
                            >
                                <FolderKanban className="size-3 shrink-0" />
                                <span className="truncate">{task.project.name}</span>
                            </span>
                        ) : (
                            task.goal && (
                                <span className="hidden @5xl:inline-flex">
                                    <GoalChip goal={task.goal} />
                                </span>
                            )
                        )}
                        {task.due_at && <DueChip due={task.due_at} closed={closed} />}
                        {who ? (
                            <span className="ml-1 flex w-36 items-center gap-2 truncate">
                                {task.assignee ? (
                                    <AgentAvatar name={who.name} className="size-5 rounded-md text-[9px]" />
                                ) : (
                                    <Monogram name={who.name} className="size-5 rounded-md text-[9px]" />
                                )}
                                <span className="truncate text-foreground/80">{who.name}</span>
                            </span>
                        ) : (
                            <span className="ml-1 w-36">Por atribuir</span>
                        )}
                    </div>
                )}
                <div className="flex shrink-0 items-center gap-3 text-xs text-muted-foreground">
                    {task.messages_count !== null && task.messages_count > 0 && (
                        <span
                            className="hidden items-center gap-1 tabular-nums @lg:inline-flex"
                            title={`${task.messages_count} mensagens na conversa, das pessoas e do agente`}
                        >
                            <MessageSquare className="size-3.5" />
                            {task.messages_count}
                        </span>
                    )}
                    <span className="text-right whitespace-nowrap @lg:w-16" title={dateTime(task.last_activity_at ?? task.created_at)}>
                        {ago(task.last_activity_at ?? task.created_at)}
                    </span>
                </div>
            </div>
        </div>
    );
}

function NewTaskDialog({
    open,
    onOpenChange,
    agents,
    people,
    goals,
    projects,
    priorities,
    defaultAgent,
    defaultProject,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agents: TaskFormOptions['agents'];
    people: TaskFormOptions['people'];
    goals: TaskFormOptions['goals'];
    projects: TaskFormOptions['projects'];
    priorities: Option[];
    defaultAgent: number | null;
    defaultProject: number | null;
}) {
    const form = useForm({
        kind: 'task' as 'task' | 'chat',
        assignee: defaultAgent ? `agent:${defaultAgent}` : '',
        title: '',
        message: '',
        priority: 'normal',
        goal_id: '',
        project_id: defaultProject ? String(defaultProject) : '',
    });
    const isChat = form.data.kind === 'chat';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            title: data.kind === 'chat' ? null : data.title,
            assignee_agent_id: data.assignee.startsWith('agent:') ? Number(data.assignee.slice(6)) : null,
            assignee_user_id: data.assignee.startsWith('user:') ? Number(data.assignee.slice(5)) : null,
            goal_id: data.goal_id ? Number(data.goal_id) : null,
            project_id: data.project_id ? Number(data.project_id) : null,
        }));
        form.post('/tasks', {
            onSuccess: () => {
                form.reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{isChat ? 'Nova conversa' : 'Nova tarefa'}</DialogTitle>
                        <DialogDescription>
                            {isChat
                                ? 'Uma conversa aberta com um agente, como assistente. Ele responde a cada mensagem.'
                                : 'Trabalho com estado: o agente pega nela, pode delegar e pedir-lhe respostas até a dar por feita.'}
                        </DialogDescription>
                    </DialogHeader>

                    <Segmented
                        value={form.data.kind}
                        onChange={(kind) => form.setData('kind', kind)}
                        options={[
                            { value: 'task', label: 'Tarefa' },
                            { value: 'chat', label: 'Conversa' },
                        ]}
                        className="w-fit"
                    />

                    <Field
                        id="assignee"
                        label={isChat ? 'Agente' : 'Responsável'}
                        error={
                            (form.errors as Record<string, string | undefined>).assignee_agent_id ??
                            (form.errors as Record<string, string | undefined>).assignee_user_id
                        }
                    >
                        <Select value={form.data.assignee || NONE} onValueChange={(value) => form.setData('assignee', value === NONE ? '' : value)}>
                            <SelectTrigger id="assignee" className="w-full">
                                <SelectValue placeholder="Escolha um agente ou uma pessoa" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Por atribuir</SelectItem>
                                {agents.map((agent) => (
                                    <SelectItem key={`agent:${agent.id}`} value={`agent:${agent.id}`}>
                                        {agent.name}
                                        {agent.title && <span className="text-muted-foreground"> · {agent.title}</span>}
                                    </SelectItem>
                                ))}
                                {!isChat &&
                                    people.map((person) => (
                                        <SelectItem key={`user:${person.id}`} value={`user:${person.id}`}>
                                            {person.name} <span className="text-muted-foreground">· pessoa</span>
                                        </SelectItem>
                                    ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    {!isChat && (
                        <Field id="title" label="Título" error={form.errors.title}>
                            <Input
                                id="title"
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="Ex.: Preparar a proposta para o concurso da EDM"
                            />
                        </Field>
                    )}

                    <Field
                        id="message"
                        label={isChat ? 'Mensagem' : 'Descrição'}
                        error={form.errors.message}
                        hint={isChat ? undefined : 'Opcional. Aceita Markdown.'}
                    >
                        <Textarea
                            id="message"
                            rows={isChat ? 5 : 4}
                            value={form.data.message}
                            onChange={(e) => form.setData('message', e.target.value)}
                            placeholder={isChat ? 'Escreva o que precisa…' : 'Contexto, critérios de conclusão, ligações…'}
                        />
                    </Field>

                    {!isChat && (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="priority" label="Prioridade" error={form.errors.priority}>
                                <Select value={form.data.priority} onValueChange={(value) => form.setData('priority', value)}>
                                    <SelectTrigger id="priority" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {priorities.map((priority) => (
                                            <SelectItem key={priority.value} value={priority.value}>
                                                <PriorityIcon priority={priority.value} label={priority.label} withLabel />
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field id="project_id" label="Projecto" error={form.errors.project_id}>
                                <Select
                                    value={form.data.project_id || NONE}
                                    onValueChange={(value) => form.setData('project_id', value === NONE ? '' : value)}
                                >
                                    <SelectTrigger id="project_id" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Nenhum</SelectItem>
                                        {projects.map((project) => (
                                            <SelectItem key={project.id} value={String(project.id)}>
                                                {project.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field id="goal_id" label="Objectivo" error={form.errors.goal_id}>
                                <Select
                                    value={form.data.goal_id || NONE}
                                    onValueChange={(value) => form.setData('goal_id', value === NONE ? '' : value)}
                                >
                                    <SelectTrigger id="goal_id" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={NONE}>Nenhum</SelectItem>
                                        {goals.map((goal) => (
                                            <SelectItem key={goal.id} value={String(goal.id)}>
                                                {goal.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>
                    )}

                    <DialogFooter className="sm:justify-between">
                        <Button type="button" variant="ghost" onClick={() => onOpenChange(false)}>
                            Cancelar
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || (isChat ? form.data.message.trim() === '' : form.data.title.trim() === '')}
                        >
                            {isChat ? 'Começar conversa' : 'Criar tarefa'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
