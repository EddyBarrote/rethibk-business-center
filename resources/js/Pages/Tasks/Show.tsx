import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Activity, ArrowUpRight, CircleHelp, CornerLeftUp, FileText, Lock, MessageSquare, Send, Wrench, Zap } from 'lucide-react';
import { type FormEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ListPanel, Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { Markdown } from '@/Components/Markdown';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { StatusDot } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, date, dateTime, usd } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AgentSummary, RunSummary, SharedProps } from '@/types';

import { ChatBadge, GoalChip, PriorityIcon, Segmented, type TaskFormOptions, TaskRow, TaskStatusIcon, type TaskSummary } from './Index';

interface Message {
    id: number;
    author_type: 'user' | 'agent' | 'system' | 'platform_admin';
    author: string;
    author_id: number | null;
    kind: 'message' | 'action' | 'event' | 'report';
    body: string;
    run_id: number | null;
    created_at: string;
}

interface Props extends TaskFormOptions {
    task: TaskSummary & {
        description: string | null;
        due_at: string | null;
        started_at: string | null;
        completed_at: string | null;
        parent: { id: number; ref: string; title: string } | null;
        source: { label: string; href: string } | null;
        agent: AgentSummary | null;
    };
    messages: Message[];
    children: TaskSummary[];
    runs: RunSummary[];
    working: RunSummary | null;
    can: { reply: boolean; update: boolean; manage_agent: boolean };
}

const NONE = 'none';
const reloadProps = ['task', 'messages', 'children', 'runs', 'working'];

export default function TaskShow({ task, messages, children, runs, working, can, agents, goals, statuses, priorities }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    const form = useForm({ body: '', mode: 'message' as 'message' | 'action' });
    const bottom = useRef<HTMLDivElement>(null);
    const seen = useRef(messages.length);
    const reload = () => router.reload({ only: reloadProps });
    // The answer as the agent writes it (TaskReplyStreaming), until the final message lands.
    const [stream, setStream] = useState<{ run_id: number; text: string; tool: string | null } | null>(null);

    useLive<{ run_id: number; text: string; tool: string | null }>(
        tenant ? `tenant.${tenant.id}.task.${task.id}` : null,
        ['TaskUpdated', 'TaskReplyStreaming'],
        (event, payload) => (event === 'TaskReplyStreaming' ? setStream(payload) : reload()),
        { only: reloadProps, poll: working !== null, intervalMs: 3000 },
    );

    // Once the run's message is in the thread (or nothing is running), the draft goes away.
    useEffect(() => {
        if (
            stream &&
            (working === null || working.id !== stream.run_id || messages.some((m) => m.run_id === stream.run_id && m.kind === 'message'))
        ) {
            setStream(null);
        }
    }, [messages, working]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        if (stream) {
            bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }
    }, [stream?.text]); // eslint-disable-line react-hooks/exhaustive-deps
    useLive(tenant ? `tenant.${tenant.id}.agents` : null, ['AgentRunStarted', 'AgentRunFinished'], reload);

    useEffect(() => {
        if (messages.length > seen.current) {
            bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }
        seen.current = messages.length;
    }, [messages.length]);

    const update = (data: Record<string, string | number | null>) => router.patch(`/tasks/${task.id}`, data, { preserveScroll: true });

    const send = (event?: FormEvent) => {
        event?.preventDefault();
        if (form.processing || form.data.body.trim() === '') {
            return;
        }
        form.post(`/tasks/${task.id}/messages`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('body');
                window.setTimeout(() => bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' }), 50);
            },
        });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) {
            event.preventDefault();
            send();
        }
    };

    const agentName = task.assignee?.name ?? task.agent?.name ?? 'O agente';
    const isClosed = task.status === 'done' || task.status === 'cancelled';
    const pendingQuestion =
        task.status === 'waiting_human'
            ? [...messages].reverse().find((m) => m.author_type === 'agent' && (m.kind === 'message' || m.kind === 'action'))
            : undefined;
    const childrenDone = children.filter((child) => child.status === 'done').length;
    const childrenActive = children.filter((child) => child.status === 'in_progress' || child.status === 'in_review').length;
    const spent = runs.reduce((sum, run) => sum + run.cost_usd, 0);

    return (
        <AppLayout
            breadcrumbs={
                task.is_conversation
                    ? [{ label: 'Conversas', href: '/tasks?view=chats' }, { label: agentName }]
                    : [{ label: 'Tarefas', href: '/tasks' }, { label: task.ref }]
            }
        >
            <Head title={task.is_conversation ? `Conversa com ${agentName}` : `${task.ref} · ${task.title}`} />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-8">
                    {task.is_conversation ? (
                        <header className="flex items-center gap-3">
                            <AgentAvatar name={agentName} className="size-10 rounded-xl text-sm" />
                            <div className="min-w-0 flex-1">
                                <h1 className="truncate text-xl font-semibold tracking-tight">{agentName}</h1>
                                <p className="truncate text-sm text-muted-foreground">
                                    {task.agent?.title ?? 'Assistente'} · uma só conversa contínua, com todo o histórico
                                </p>
                            </div>
                            {task.assignee && (
                                <Link href={`/agents/${task.assignee.id}`} className="shrink-0 text-sm text-muted-foreground hover:text-foreground">
                                    Ver agente
                                </Link>
                            )}
                            {task.assignee && can.manage_agent && (
                                <Link href={`/agents/${task.assignee.id}/edit`} className="shrink-0 text-sm text-muted-foreground hover:text-foreground">
                                    Editar agente
                                </Link>
                            )}
                        </header>
                    ) : (
                        <header className="flex flex-col gap-3">
                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                <TaskStatusIcon status={task.status} label={task.status_label} />
                                <span className="font-mono text-muted-foreground">{task.ref}</span>
                                <span className="text-xs text-muted-foreground">{task.status_label}</span>
                                {task.priority !== 'normal' && (
                                    <span className="text-xs text-muted-foreground">
                                        <PriorityIcon priority={task.priority} label={task.priority_label} withLabel />
                                    </span>
                                )}
                                {task.kind === 'chat' && <ChatBadge />}
                                {task.goal && (
                                    <Link href={`/tasks?view=all&goal=${task.goal.id}`}>
                                        <GoalChip goal={task.goal} />
                                    </Link>
                                )}
                            </div>
                            <h1 className="text-xl font-semibold tracking-tight text-balance">{task.title}</h1>
                            {task.parent && (
                                <Link
                                    href={`/tasks/${task.parent.id}`}
                                    className="flex w-fit items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground"
                                >
                                    <CornerLeftUp className="size-3.5" />
                                    Sub-tarefa de <span className="font-mono">{task.parent.ref}</span>
                                    <span className="max-w-72 truncate">{task.parent.title}</span>
                                </Link>
                            )}
                            {task.description && (
                                <div className="text-foreground/90">
                                    <Markdown>{task.description}</Markdown>
                                </div>
                            )}
                        </header>
                    )}

                    {children.length > 0 && (
                        <Section title="Sub-tarefas delegadas">
                            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                                    <span className="font-medium tabular-nums">
                                        {childrenDone}/{children.length} feitas
                                    </span>
                                    <span className="text-xs text-muted-foreground tabular-nums">{childrenActive} em curso</span>
                                </div>
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-status-success transition-all"
                                        style={{ width: `${(childrenDone / children.length) * 100}%` }}
                                    />
                                </div>
                            </div>
                            <ListPanel>
                                {children.map((child) => (
                                    <TaskRow key={child.id} task={child} compact />
                                ))}
                            </ListPanel>
                        </Section>
                    )}

                    <Section title={task.kind === 'chat' ? 'Conversa' : 'Conversa e actividade'}>
                        <div className="flex flex-col gap-1">
                            {messages.length === 0 && !working && (
                                <EmptyState
                                    icon={MessageSquare}
                                    title="Ainda sem mensagens"
                                    description={`Escreva abaixo para dar contexto ou instruções a ${agentName}.`}
                                />
                            )}

                            {messages.map((message) =>
                                message.kind === 'event' ? (
                                    <EventLine key={message.id} message={message} />
                                ) : message.kind === 'report' ? (
                                    <ReportLine key={message.id} message={message} />
                                ) : (
                                    <MessageRow key={message.id} message={message} />
                                ),
                            )}

                            {(working || stream) && (
                                <div className="flex gap-3 rounded-xl bg-accent/40 px-3 py-3">
                                    <span className="relative h-fit">
                                        <AgentAvatar name={working?.agent.name ?? agentName} />
                                        <StatusDot tone="running" className="absolute -right-0.5 -bottom-0.5" />
                                    </span>
                                    <div className="flex min-w-0 flex-1 flex-col gap-1">
                                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                                            <span className="font-medium">{working?.agent.name ?? agentName}</span>
                                            {stream?.tool ? (
                                                <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                                    <Wrench className="size-3" />a usar <span className="font-mono">{stream.tool}</span>
                                                </span>
                                            ) : (
                                                !stream?.text && <span className="animate-pulse text-muted-foreground">A pensar…</span>
                                            )}
                                            {working && (
                                                <Link
                                                    href={`/runs/${working.id}`}
                                                    className="font-mono text-xs text-muted-foreground hover:text-foreground"
                                                >
                                                    ver execução #{working.id}
                                                </Link>
                                            )}
                                        </div>
                                        {stream?.text && (
                                            <div className="text-sm [&>*:last-child]:after:ml-0.5 [&>*:last-child]:after:inline-block [&>*:last-child]:after:h-4 [&>*:last-child]:after:w-1.5 [&>*:last-child]:after:animate-pulse [&>*:last-child]:after:bg-primary [&>*:last-child]:after:align-middle [&>*:last-child]:after:content-['']">
                                                <Markdown>{stream.text}</Markdown>
                                            </div>
                                        )}
                                    </div>
                                </div>
                            )}
                            <div ref={bottom} />
                        </div>
                    </Section>

                    <div className="sticky bottom-0 z-10 -mx-1 -mt-4 flex flex-col gap-2 bg-background/95 px-1 pt-2 pb-4 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                        {task.status === 'waiting_human' ? (
                            <div className="flex gap-3 rounded-xl border border-status-warning/40 bg-status-warning/10 px-4 py-3">
                                <CircleHelp className="mt-0.5 size-4 shrink-0 text-status-warning" />
                                <div className="min-w-0 space-y-1 text-sm">
                                    <p className="font-medium">{agentName} está à tua espera</p>
                                    {pendingQuestion && <p className="line-clamp-2 text-muted-foreground">{pendingQuestion.body}</p>}
                                    <p className="text-xs text-muted-foreground">Responda abaixo para a tarefa continuar.</p>
                                </div>
                            </div>
                        ) : null}

                        {can.reply ? (
                            <form onSubmit={send} className="rounded-xl border bg-card shadow-xs focus-within:ring-[3px] focus-within:ring-ring/30">
                                <Textarea
                                    rows={3}
                                    value={form.data.body}
                                    onChange={(e) => form.setData('body', e.target.value)}
                                    onKeyDown={onKeyDown}
                                    placeholder={
                                        form.data.mode === 'action'
                                            ? `Diga a ${agentName} o que executar agora…`
                                            : task.status === 'waiting_human'
                                              ? 'Escreva a sua resposta…'
                                              : `Escreva a ${agentName}…`
                                    }
                                    className="min-h-20 resize-none border-0 bg-transparent shadow-none focus-visible:ring-0 dark:bg-transparent"
                                />
                                <div className="flex flex-wrap items-center gap-2 border-t px-2 py-2">
                                    <Segmented
                                        value={form.data.mode}
                                        onChange={(mode) => form.setData('mode', mode)}
                                        options={[
                                            { value: 'message', label: 'Conversar', title: 'O agente responde e continua o trabalho' },
                                            {
                                                value: 'action',
                                                label: (
                                                    <>
                                                        <Zap />
                                                        Executar
                                                    </>
                                                ),
                                                title: 'Acção directa: o agente executa já e reporta',
                                            },
                                        ]}
                                    />
                                    <span className="hidden flex-1 text-xs text-muted-foreground sm:block">
                                        {isClosed ? 'Escrever reabre a tarefa. ' : ''}Enter envia · Shift+Enter nova linha
                                    </span>
                                    <Button type="submit" size="sm" className="ml-auto" disabled={form.processing || form.data.body.trim() === ''}>
                                        {form.data.mode === 'action' ? <Zap /> : <Send />}
                                        {form.data.mode === 'action' ? 'Executar' : 'Enviar'}
                                    </Button>
                                </div>
                                {(form.errors.body || form.errors.mode) && (
                                    <div className="px-3 pb-2">
                                        <InputError message={form.errors.body ?? form.errors.mode} />
                                    </div>
                                )}
                            </form>
                        ) : (
                            <div className="flex items-center gap-2 rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">
                                <Lock className="size-4 shrink-0" />
                                Só quem participa nesta tarefa ou responde pelo agente pode escrever aqui.
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-8 self-start">
                    {task.is_conversation ? (
                        <Properties title="Conversa">
                            <Property label="Agente">
                                {task.assignee && (
                                    <Link href={`/agents/${task.assignee.id}`} className="hover:underline">
                                        <AgentOption name={task.assignee.name} />
                                    </Link>
                                )}
                            </Property>
                            <Property label="Com">{task.user}</Property>
                            <Property label="Estado">
                                <StatusOption status={task.status} label={task.status_label} />
                            </Property>
                            <Property label="Desde">{dateTime(task.created_at)}</Property>
                            <Property label="Mensagens">
                                <span className="font-mono tabular-nums">{messages.length}</span>
                            </Property>
                        </Properties>
                    ) : (
                        <Properties>
                            <Property label="Estado">
                                {can.update ? (
                                    <InlineSelect
                                        value={task.status}
                                        onChange={(status) => update({ status })}
                                        options={statuses.map((s) => ({ value: s.value, label: <StatusOption status={s.value} label={s.label} /> }))}
                                    />
                                ) : (
                                    <StatusOption status={task.status} label={task.status_label} />
                                )}
                            </Property>
                            <Property label="Prioridade">
                                {can.update ? (
                                    <InlineSelect
                                        value={task.priority}
                                        onChange={(priority) => update({ priority })}
                                        options={priorities.map((p) => ({
                                            value: p.value,
                                            label: <PriorityIcon priority={p.value} label={p.label} withLabel />,
                                        }))}
                                    />
                                ) : (
                                    <PriorityIcon priority={task.priority} label={task.priority_label} withLabel />
                                )}
                            </Property>
                            <Property label="Agente">
                                {can.update ? (
                                    <InlineSelect
                                        value={task.assignee ? String(task.assignee.id) : NONE}
                                        onChange={(value) => update({ assignee_agent_id: value === NONE ? null : Number(value) })}
                                        options={[
                                            { value: NONE, label: <span className="text-muted-foreground">Sem agente</span> },
                                            ...withCurrent(agents, task.assignee).map((agent) => ({
                                                value: String(agent.id),
                                                label: <AgentOption name={agent.name} />,
                                            })),
                                        ]}
                                    />
                                ) : task.assignee ? (
                                    <Link href={`/agents/${task.assignee.id}`} className="hover:underline">
                                        <AgentOption name={task.assignee.name} />
                                    </Link>
                                ) : null}
                            </Property>
                            <Property label="Objectivo">
                                {can.update ? (
                                    <InlineSelect
                                        value={task.goal ? String(task.goal.id) : NONE}
                                        onChange={(value) => update({ goal_id: value === NONE ? null : Number(value) })}
                                        options={[
                                            { value: NONE, label: <span className="text-muted-foreground">Nenhum</span> },
                                            ...withCurrent(
                                                goals.map((g) => ({ id: g.id, name: g.title })),
                                                task.goal ? { id: task.goal.id, name: task.goal.title } : null,
                                            ).map((goal) => ({ value: String(goal.id), label: goal.name })),
                                        ]}
                                    />
                                ) : (
                                    task.goal?.title
                                )}
                            </Property>
                            <div className="my-2 border-t" />
                            <Property label="Com">{task.user}</Property>
                            <Property label="Criada por">
                                {task.created_by && (
                                    <span className="inline-flex items-center gap-1.5">
                                        {task.created_by_agent && <AgentAvatar name={task.created_by} className="size-5 rounded-md text-[9px]" />}
                                        {task.created_by}
                                    </span>
                                )}
                            </Property>
                            <Property label="Origem">
                                {task.source && (
                                    <Link href={task.source.href} className="truncate hover:underline">
                                        {task.source.label}
                                    </Link>
                                )}
                            </Property>
                            <Property label="Criada">
                                <span title={dateTime(task.created_at)}>{dateTime(task.created_at)}</span>
                            </Property>
                            <Property label="Iniciada">{task.started_at ? dateTime(task.started_at) : null}</Property>
                            <Property label="Concluída">{task.completed_at ? dateTime(task.completed_at) : null}</Property>
                            <Property label="Prazo">{task.due_at ? date(task.due_at) : null}</Property>
                        </Properties>
                    )}

                    <Section
                        title="Execuções"
                        action={
                            runs.length > 0 && (
                                <span className="font-mono text-xs text-muted-foreground tabular-nums" title="Soma das execuções mostradas">
                                    {usd(spent)}
                                </span>
                            )
                        }
                    >
                        {runs.length === 0 ? (
                            <div className="flex items-center gap-2 rounded-xl border border-dashed px-4 py-3 text-xs text-muted-foreground">
                                <Activity className="size-3.5" />O agente ainda não trabalhou nesta tarefa.
                            </div>
                        ) : (
                            <ListPanel>
                                {runs.map((run) => (
                                    <Link
                                        key={run.id}
                                        href={`/runs/${run.id}`}
                                        className="flex items-center gap-2 px-3 py-2 text-xs transition-colors hover:bg-accent/60"
                                    >
                                        <span className="w-12 font-mono text-muted-foreground tabular-nums">#{run.id}</span>
                                        <RunStatusBadge status={run.status} label={run.status_label} />
                                        <span className="flex-1 truncate text-right text-muted-foreground" title={dateTime(run.created_at)}>
                                            {ago(run.created_at)}
                                        </span>
                                        <span className="w-14 text-right font-mono tabular-nums">{usd(run.cost_usd)}</span>
                                    </Link>
                                ))}
                            </ListPanel>
                        )}
                    </Section>
                </div>
            </div>
        </AppLayout>
    );
}

function withCurrent<T extends { id: number; name: string }>(
    list: T[],
    current: { id: number; name: string } | null,
): { id: number; name: string }[] {
    return current && !list.some((item) => item.id === current.id) ? [current, ...list] : list;
}

function StatusOption({ status, label }: { status: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-2">
            <TaskStatusIcon status={status} className="size-3.5" />
            {label}
        </span>
    );
}

function AgentOption({ name }: { name: string }) {
    return (
        <span className="inline-flex min-w-0 items-center gap-2">
            <AgentAvatar name={name} className="size-5 rounded-md text-[9px]" />
            <span className="truncate">{name}</span>
        </span>
    );
}

function InlineSelect({
    value,
    onChange,
    options,
}: {
    value: string;
    onChange: (value: string) => void;
    options: { value: string; label: React.ReactNode }[];
}) {
    return (
        <Select value={value} onValueChange={(next) => next !== value && onChange(next)}>
            <SelectTrigger
                size="sm"
                className="-ml-2 h-7 w-full max-w-full border-transparent px-2 shadow-none hover:bg-accent/60 dark:bg-transparent"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function RunLink({ id }: { id: number }) {
    return (
        <Link href={`/runs/${id}`} className="inline-flex items-center gap-0.5 font-mono text-[11px] text-muted-foreground hover:text-foreground">
            ver execução #{id}
            <ArrowUpRight className="size-3" />
        </Link>
    );
}

function MessageRow({ message }: { message: Message }) {
    const isAgent = message.author_type === 'agent';
    const isAction = message.kind === 'action';

    return (
        <div className={cn('flex gap-3 rounded-xl px-3 py-3', isAgent && 'bg-muted/40 dark:bg-muted/25')}>
            {isAgent ? <AgentAvatar name={message.author} /> : <Monogram name={message.author} />}
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="text-sm font-medium">{message.author}</span>
                    {isAgent && <span className="text-[11px] text-muted-foreground">agente</span>}
                    {isAction && (
                        <span className="inline-flex h-5 items-center gap-1 rounded-full bg-status-warning/15 px-2 text-[11px] font-medium text-[color-mix(in_oklch,var(--status-warning)_70%,var(--foreground))]">
                            <Zap className="size-3" />
                            Acção directa
                        </span>
                    )}
                    <span className="text-xs text-muted-foreground" title={dateTime(message.created_at)}>
                        {ago(message.created_at)}
                    </span>
                    {message.run_id && (
                        <span className="ml-auto">
                            <RunLink id={message.run_id} />
                        </span>
                    )}
                </div>
                <div className="mt-0.5 [&>div>p:first-child]:mt-0 [&>div>p:last-child]:mb-0">
                    <Markdown>{message.body}</Markdown>
                </div>
            </div>
        </div>
    );
}

function EventLine({ message }: { message: Message }) {
    return (
        <div className="flex items-center gap-3 px-3 py-1.5 text-xs text-muted-foreground">
            <span className="h-px flex-1 bg-border" />
            <span className="max-w-[80%] text-center">
                {message.body}
                <span className="ml-1.5 opacity-70" title={dateTime(message.created_at)}>
                    · {ago(message.created_at)}
                </span>
                {message.run_id && (
                    <span className="ml-1.5">
                        · <RunLink id={message.run_id} />
                    </span>
                )}
            </span>
            <span className="h-px flex-1 bg-border" />
        </div>
    );
}

function ReportLine({ message }: { message: Message }) {
    return (
        <details className="group mx-3 my-1 rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground">
            <summary className="flex cursor-pointer list-none items-center gap-2 [&::-webkit-details-marker]:hidden">
                <FileText className="size-3.5 shrink-0" />
                <span className="min-w-0 flex-1 truncate">
                    Relatório de <span className="font-medium text-foreground/80">{message.author}</span>
                    <span className="ml-1.5 group-open:hidden">— {message.body.slice(0, 120)}</span>
                </span>
                <span className="shrink-0" title={dateTime(message.created_at)}>
                    {ago(message.created_at)}
                </span>
                {message.run_id && <RunLink id={message.run_id} />}
            </summary>
            <div className="mt-2 border-t pt-2 text-foreground">
                <Markdown>{message.body}</Markdown>
            </div>
        </details>
    );
}
