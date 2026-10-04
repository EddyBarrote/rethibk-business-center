import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ChevronRight, CircleAlert, ListTodo, MessageSquare, MessagesSquare, Plus, Search } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useRef, useState } from 'react';

import { Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, SharedProps } from '@/types';

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
    user: string | null;
    created_by: string | null;
    created_by_agent: boolean;
    goal: { id: number; title: string } | null;
    messages_count: number | null;
    last_activity_at: string | null;
    created_at: string;
    working?: boolean;
}

export interface TaskFormOptions {
    agents: { id: number; name: string; title: string | null }[];
    goals: { id: number; title: string }[];
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

interface Filters {
    view: View;
    agent: number | null;
    goal: number | null;
    q: string;
}

interface Props extends TaskFormOptions {
    tasks: TaskSummary[];
    filters: Filters;
    counts: { mine: number; waiting: number };
}

const NONE = 'none';

const emptyCopy: Record<View, { title: string; description: string }> = {
    mine: { title: 'Nada contigo', description: 'Crie uma tarefa ou comece uma conversa com um agente para ela aparecer aqui.' },
    waiting: { title: 'Nenhum agente à tua espera', description: 'Quando um agente precisar de uma resposta tua, a tarefa aparece aqui.' },
    all: { title: 'Sem tarefas abertas', description: 'Crie a primeira tarefa e atribua-a a um agente.' },
    chats: { title: 'Sem conversas', description: 'Comece uma conversa com um agente para lhe pedir ajuda como assistente.' },
    closed: { title: 'Nada fechado', description: 'As tarefas feitas ou canceladas aparecem aqui.' },
};

export default function TasksIndex({ tasks, filters, counts, agents, goals, priorities }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState(filters.q ?? '');
    const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
    const firstRender = useRef(true);

    useLive(tenant ? `tenant.${tenant.id}.tasks` : null, ['TaskUpdated'], () => router.reload({ only: ['tasks', 'counts'] }), {
        only: ['tasks', 'counts'],
        poll: true,
        intervalMs: 5000,
    });

    const visit = (next: Partial<Filters>) => {
        const merged = { ...filters, q, ...next };
        const params: Record<string, string | number> = { view: merged.view };
        if (merged.agent) params.agent = merged.agent;
        if (merged.goal) params.goal = merged.goal;
        if (merged.q) params.q = merged.q;
        router.get('/tasks', params, { preserveState: true, preserveScroll: true, replace: true });
    };

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        const timer = window.setTimeout(() => visit({ q }), 300);
        return () => window.clearTimeout(timer);
    }, [q]);

    const groups = statusOrder
        .map((status) => ({ status, items: tasks.filter((task) => task.status === status) }))
        .filter((group) => group.items.length > 0);
    const goalFilter = filters.goal ? goals.find((goal) => goal.id === filters.goal) : null;

    return (
        <AppLayout wide>
            <Head title="Tarefas" />

            <PageHeader
                title="Tarefas"
                description="O trabalho dos agentes e as conversas consigo: o que está em curso, o que espera por si."
                actions={
                    <Button onClick={() => setOpen(true)}>
                        <Plus />
                        Nova tarefa
                    </Button>
                }
            />

            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                    <Tabs value={filters.view} onValueChange={(view) => visit({ view: view as View })} className="min-w-0">
                        <TabsList variant="line" className="h-9 max-w-full justify-start overflow-x-auto">
                            <TabsTrigger value="mine" className="flex-none">
                                Minhas
                                <Count value={counts.mine} />
                            </TabsTrigger>
                            <TabsTrigger value="waiting" className="flex-none">
                                À tua espera
                                <Count value={counts.waiting} warn />
                            </TabsTrigger>
                            <TabsTrigger value="all" className="flex-none">
                                Todas
                            </TabsTrigger>
                            <TabsTrigger value="chats" className="flex-none">
                                Conversas
                            </TabsTrigger>
                            <TabsTrigger value="closed" className="flex-none">
                                Fechadas
                            </TabsTrigger>
                        </TabsList>
                    </Tabs>

                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        {goalFilter && (
                            <button
                                type="button"
                                onClick={() => visit({ goal: null })}
                                className="inline-flex h-8 items-center gap-1.5 rounded-md border px-2.5 text-xs text-muted-foreground hover:text-foreground"
                                title="Retirar filtro"
                            >
                                Objectivo: <span className="max-w-40 truncate text-foreground">{goalFilter.title}</span> ×
                            </button>
                        )}
                        <Select
                            value={filters.agent ? String(filters.agent) : NONE}
                            onValueChange={(value) => visit({ agent: value === NONE ? null : Number(value) })}
                        >
                            <SelectTrigger size="sm" className="w-full sm:w-48">
                                <SelectValue placeholder="Agente" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Todos os agentes</SelectItem>
                                {agents.map((agent) => (
                                    <SelectItem key={agent.id} value={String(agent.id)}>
                                        {agent.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <div className="relative sm:w-64">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Procurar por título…" className="h-8 pl-8 text-sm" />
                        </div>
                    </div>
                </div>

                {groups.length === 0 ? (
                    <EmptyState
                        icon={filters.view === 'chats' ? MessagesSquare : ListTodo}
                        title={filters.q || filters.agent || filters.goal ? 'Nada encontrado' : emptyCopy[filters.view].title}
                        description={
                            filters.q || filters.agent || filters.goal
                                ? 'Experimente retirar os filtros ou mudar de vista.'
                                : emptyCopy[filters.view].description
                        }
                        action={
                            <Button variant="outline" size="sm" onClick={() => setOpen(true)}>
                                <Plus />
                                Nova tarefa
                            </Button>
                        }
                    />
                ) : (
                    <div className="flex flex-col gap-5">
                        {groups.map((group) => {
                            const first = group.items[0];
                            const isCollapsed = collapsed[group.status] ?? false;

                            return (
                                <div key={group.status} className="flex flex-col gap-1.5">
                                    <button
                                        type="button"
                                        onClick={() => setCollapsed({ ...collapsed, [group.status]: !isCollapsed })}
                                        className="flex w-fit items-center gap-2 rounded-md px-1 py-0.5 text-sm font-medium hover:bg-accent/60"
                                        aria-expanded={!isCollapsed}
                                    >
                                        <ChevronRight
                                            className={cn('size-3.5 text-muted-foreground transition-transform', !isCollapsed && 'rotate-90')}
                                        />
                                        <TaskStatusIcon status={group.status} />
                                        {first.status_label}
                                        <span className="font-mono text-xs text-muted-foreground tabular-nums">{group.items.length}</span>
                                    </button>
                                    {!isCollapsed && (
                                        <div className="divide-y overflow-hidden rounded-xl border bg-card">
                                            {group.items.map((task) => (
                                                <TaskRow key={task.id} task={task} />
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                        {tasks.length >= 200 && (
                            <p className="text-center text-xs text-muted-foreground">A mostrar as 200 mais recentes. Use os filtros para afinar.</p>
                        )}
                    </div>
                )}
            </div>

            <NewTaskDialog open={open} onOpenChange={setOpen} agents={agents} goals={goals} priorities={priorities} defaultAgent={filters.agent} />
        </AppLayout>
    );
}

function Count({ value, warn = false }: { value: number; warn?: boolean }) {
    if (value === 0) {
        return null;
    }

    return (
        <span
            className={cn(
                'inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full px-1 font-mono text-[10px] tabular-nums',
                warn
                    ? 'bg-status-warning/20 text-[color-mix(in_oklch,var(--status-warning)_70%,var(--foreground))]'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            {value}
        </span>
    );
}

export function TaskRow({ task, compact = false }: { task: TaskSummary; compact?: boolean }) {
    return (
        <Link href={`/tasks/${task.id}`} className="group flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-accent/60">
            <TaskStatusIcon status={task.status} label={task.status_label} />
            <span className="hidden w-16 shrink-0 font-mono text-xs text-muted-foreground sm:inline">{task.ref}</span>
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-2">
                    <PriorityIcon priority={task.priority} label={task.priority_label} />
                    <span className="truncate text-sm font-medium">{task.title}</span>
                    {task.kind === 'chat' && <ChatBadge />}
                    {task.working && <WorkingPulse />}
                </div>
                <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                    <span className="font-mono sm:hidden">{task.ref}</span>
                    {task.assignee && <span className={cn('truncate', !compact && 'md:hidden')}>{task.assignee.name}</span>}
                    {task.created_by_agent && task.created_by && <span className="truncate">delegada por {task.created_by}</span>}
                    {!task.created_by_agent && task.user && !compact && <span className="hidden truncate lg:inline">com {task.user}</span>}
                </div>
            </div>
            {!compact && (
                <div className="hidden shrink-0 items-center gap-3 text-xs text-muted-foreground md:flex">
                    {task.goal && <GoalChip goal={task.goal} />}
                    {task.assignee ? (
                        <span className="flex w-36 items-center gap-2 truncate">
                            <Monogram name={task.assignee.name} agent className="size-5 rounded-md text-[9px]" />
                            <span className="truncate text-foreground/80">{task.assignee.name}</span>
                        </span>
                    ) : (
                        <span className="w-36">Sem agente</span>
                    )}
                </div>
            )}
            <div className="flex shrink-0 items-center gap-3 text-xs text-muted-foreground">
                {task.messages_count !== null && task.messages_count > 0 && (
                    <span className="hidden items-center gap-1 tabular-nums sm:inline-flex" title={`${task.messages_count} mensagens`}>
                        <MessageSquare className="size-3.5" />
                        {task.messages_count}
                    </span>
                )}
                <span className="w-16 text-right" title={dateTime(task.last_activity_at ?? task.created_at)}>
                    {ago(task.last_activity_at ?? task.created_at)}
                </span>
            </div>
        </Link>
    );
}

function NewTaskDialog({
    open,
    onOpenChange,
    agents,
    goals,
    priorities,
    defaultAgent,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agents: TaskFormOptions['agents'];
    goals: TaskFormOptions['goals'];
    priorities: Option[];
    defaultAgent: number | null;
}) {
    const form = useForm({
        kind: 'task' as 'task' | 'chat',
        assignee_agent_id: defaultAgent ? String(defaultAgent) : '',
        title: '',
        message: '',
        priority: 'normal',
        goal_id: '',
    });
    const isChat = form.data.kind === 'chat';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            title: data.kind === 'chat' ? null : data.title,
            assignee_agent_id: data.assignee_agent_id ? Number(data.assignee_agent_id) : null,
            goal_id: data.goal_id ? Number(data.goal_id) : null,
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

                    <Field id="assignee_agent_id" label="Agente" error={form.errors.assignee_agent_id}>
                        <Select
                            value={form.data.assignee_agent_id || NONE}
                            onValueChange={(value) => form.setData('assignee_agent_id', value === NONE ? '' : value)}
                        >
                            <SelectTrigger id="assignee_agent_id" className="w-full">
                                <SelectValue placeholder="Escolha um agente" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Sem agente (por atribuir)</SelectItem>
                                {agents.map((agent) => (
                                    <SelectItem key={agent.id} value={String(agent.id)}>
                                        {agent.name}
                                        {agent.title && <span className="text-muted-foreground"> · {agent.title}</span>}
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
