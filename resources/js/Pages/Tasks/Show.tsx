import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CircleHelp, CornerLeftUp, Lock, MessageSquare, PanelRight, Pencil, Route, Send, UserRound, Zap } from 'lucide-react';
import { type FormEvent, type KeyboardEvent, useEffect, useRef, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ElsewherePanel, type NowRun } from '@/Components/agents/AgentNow';
import { AttachButton, AttachmentTray, DropOverlay, RecordControl, useFileDrop } from '@/Components/attachments/Composer';
import { ListPanel, Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { Markdown } from '@/Components/Markdown';
import { type ChatMessage, ChatThread, type LiveReply, type Thought, type ToolCall, WorkingBubble } from '@/Components/tasks/Chat';
import { type Journey, JourneyView } from '@/Components/tasks/Journey';
import { type AgentWork, type PanelRun, type PanelTab, SidePanel } from '@/Components/tasks/SidePanel';
import { StatusBadge, StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Sheet, SheetContent, SheetTitle } from '@/Components/ui/sheet';
import { Textarea } from '@/Components/ui/textarea';
import { useAttachments } from '@/hooks/useAttachments';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { approvalTitle } from '@/lib/approvals';
import type { AttachmentLimits } from '@/lib/attachments';
import { date, dateTime, plainText } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { AgentSummary, RunSummary, SharedProps } from '@/types';

import { ChatBadge, GoalChip, PriorityIcon, Segmented, type TaskFormOptions, TaskRow, TaskStatusIcon, type TaskSummary } from './Index';

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
    messages: ChatMessage[];
    children: TaskSummary[];
    runs: RunSummary[];
    working: RunSummary | null;
    tool_calls: Record<number, ToolCall[]>;
    thinking: Record<number, Thought>;
    panel_run: PanelRun | null;
    tool_names: Record<string, string>;
    approvals: PendingApproval[];
    workflow: WorkflowInfo | null;
    has_journey: boolean;
    journey?: Journey;
    attachment_limits: AttachmentLimits;
    can: { reply: boolean; update: boolean; manage_agent: boolean };
    /** In the person's own conversation: their tasks with this agent and its approvals (side panel "Tarefas"). */
    agent_work: AgentWork | null;
    /** In a conversation, the agent's live work outside it (App\\Ai\\Runs\\AgentNow). */
    agent_elsewhere: NowRun[];
    agent_elsewhere_done: NowRun[];
}

/** The flow this task follows, or the decision of a flow's step it asks of a person (docs/DECISOES.md, "Fluxos de trabalho"). */
interface WorkflowInfo {
    progress: {
        workflow: { id: number; name: string };
        status: string;
        status_label: string;
        steps: {
            id: number;
            label: string;
            kind: string;
            status: string;
            status_label: string;
            item: string | null;
            answer: string | null;
            task: { id: number; ref: string } | null;
        }[];
    } | null;
    decision: { step_id: number; workflow: string; question: string; options: { value: string; label: string }[]; can_decide: boolean } | null;
}

const NONE = 'none';
const reloadProps = [
    'task',
    'messages',
    'children',
    'runs',
    'working',
    'tool_calls',
    'thinking',
    'approvals',
    'workflow',
    'has_journey',
    'agent_work',
    'agent_elsewhere',
    'agent_elsewhere_done',
    'sidebar_agents',
];
const PANEL_KEY = 'micomoc.conversa.painel';

interface PendingApproval {
    id: number;
    summary: string;
    action_type: string;
    payload: Record<string, unknown> | null;
    ceiling_reason: string | null;
    can_decide: boolean;
}

/** Wide enough for the chat and the side panel next to each other; below this the panel slides over. */
function useWide() {
    const query = '(min-width: 1280px)';
    const [wide, setWide] = useState(() => typeof window !== 'undefined' && window.matchMedia(query).matches);

    useEffect(() => {
        const media = window.matchMedia(query);
        const onChange = () => setWide(media.matches);
        media.addEventListener('change', onChange);

        return () => media.removeEventListener('change', onChange);
    }, []);

    return wide;
}

function remembered(key: string) {
    try {
        return window.localStorage.getItem(key);
    } catch {
        return null;
    }
}

function remember(key: string, value: string) {
    try {
        window.localStorage.setItem(key, value);
    } catch {
        // Private windows may refuse storage; the panel just opens by default next time.
    }
}

/** The address with ?execucao= set or removed, keeping the rest (?ver=percurso). */
function withRun(runId: number | null) {
    const url = new URL(window.location.href);
    if (runId === null) {
        url.searchParams.delete('execucao');
    } else {
        url.searchParams.set('execucao', String(runId));
    }

    return url.pathname + url.search;
}

export default function TaskShow({
    task,
    messages,
    children,
    runs,
    working,
    tool_calls: toolCalls,
    thinking,
    panel_run: panelRun,
    agent_work: agentWork,
    agent_elsewhere: elsewhere,
    agent_elsewhere_done: elsewhereDone,
    tool_names: toolNames,
    approvals,
    workflow,
    has_journey,
    journey,
    attachment_limits,
    can,
    agents,
    people,
    goals,
    projects,
    statuses,
    priorities,
}: Props) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const form = useForm({ body: '', mode: 'message' as 'message' | 'action', attachment_ids: [] as number[] });
    const files = useAttachments(attachment_limits, { task_id: task.id });
    const drop = useFileDrop(files.add, can.reply);
    const bottom = useRef<HTMLDivElement>(null);
    const seen = useRef(messages.length);
    const wide = useWide();

    // The side panel (docs/DECISOES.md, "Página de conversa"): open by default on a wide screen, remembered per browser.
    const initialRun = panelRun?.run.id ?? null;
    const [panelOpen, setPanelOpen] = useState(() => initialRun !== null || (wide && remembered(PANEL_KEY) !== 'closed'));
    const [tab, setTab] = useState<PanelTab>(initialRun !== null ? 'runs' : 'details');
    const [runId, setRunId] = useState<number | null>(initialRun);
    const [stepId, setStepId] = useState<number | null>(null);

    const togglePanel = (open: boolean) => {
        setPanelOpen(open);
        if (wide) {
            remember(PANEL_KEY, open ? 'open' : 'closed');
        }
    };

    const openRun = (id: number, step?: number) => {
        setRunId(id);
        setStepId(step ?? null);
        setTab('runs');
        setPanelOpen(true);
        if (id !== panelRun?.run.id) {
            router.get(withRun(id), {}, { only: ['panel_run'], preserveState: true, preserveScroll: true, replace: true });
        }
    };

    const closeRun = () => {
        setRunId(null);
        setStepId(null);
        router.get(withRun(null), {}, { only: ['panel_run'], preserveState: true, preserveScroll: true, replace: true });
    };

    // "Conversa" or "Percurso" (the path of the case across agents and people); kept in the address.
    const [view, setView] = useState<'conversation' | 'journey'>(() =>
        has_journey && new URLSearchParams(window.location.search).get('ver') === 'percurso' ? 'journey' : 'conversation',
    );
    const live = view === 'journey' ? [...reloadProps, 'journey'] : reloadProps;
    const reload = () => router.reload({ only: live });
    const openView = (next: 'conversation' | 'journey') => {
        setView(next);
        const url = new URL(window.location.href);
        if (next === 'journey') {
            url.searchParams.set('ver', 'percurso');
        } else {
            url.searchParams.delete('ver');
        }
        window.history.replaceState(window.history.state, '', url);
        if (next === 'journey') {
            router.reload({ only: ['journey'] });
        }
    };

    useEffect(() => {
        if (view === 'journey' && !journey) {
            router.reload({ only: ['journey'] });
        }
        bottom.current?.scrollIntoView({ block: 'end' });
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    // The answer as the agent writes it (TaskReplyStreaming), until the final message lands.
    const [stream, setStream] = useState<LiveReply | null>(null);

    useLive<LiveReply>(
        tenant ? `tenant.${tenant.id}.task.${task.id}` : null,
        ['TaskUpdated', 'TaskReplyStreaming'],
        (event, payload) => (event === 'TaskReplyStreaming' ? setStream(payload) : reload()),
        { only: live, poll: working !== null, intervalMs: 3000 },
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
    }, [stream?.text, stream?.reasoning]); // eslint-disable-line react-hooks/exhaustive-deps
    // Its runs elsewhere starting or ending refresh "A fazer agora"; polled too while there are some, so it never sticks.
    useLive(tenant ? `tenant.${tenant.id}.agents` : null, ['AgentRunStarted', 'AgentRunFinished'], reload, {
        only: live,
        poll: elsewhere.length > 0,
    });

    useEffect(() => {
        if (messages.length > seen.current) {
            bottom.current?.scrollIntoView({ behavior: 'smooth', block: 'end' });
        }
        seen.current = messages.length;
    }, [messages.length]);

    const update = (data: Record<string, string | number | null>) => router.patch(`/tasks/${task.id}`, data, { preserveScroll: true });

    const canSend = !form.processing && !files.uploading && !files.failed && (form.data.body.trim() !== '' || files.ids.length > 0);

    const send = (event?: FormEvent) => {
        event?.preventDefault();
        if (!canSend) {
            return;
        }
        form.transform((data) => ({ ...data, attachment_ids: files.ids }));
        form.post(`/tasks/${task.id}/messages`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('body');
                files.clear();
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
    // Someone else's conversation, open to read for whoever may read them all (never to write).
    const someoneElses = task.is_conversation && task.user_id !== auth.user?.id;
    const isClosed = task.status === 'done' || task.status === 'cancelled';
    const pendingQuestion =
        task.status === 'waiting_human'
            ? [...messages].reverse().find((m) => m.author_type === 'agent' && (m.kind === 'message' || m.kind === 'action'))
            : undefined;
    // Questions asked with questions.ask are answered on their card, in the conversation.
    const pendingCard = messages.some((m) => m.question?.status === 'pending');
    const childrenDone = children.filter((child) => child.status === 'done').length;
    const childrenActive = children.filter((child) => child.status === 'in_progress' || child.status === 'in_review').length;

    const details = (
        <>
            {workflow?.progress && <WorkflowProgress progress={workflow.progress} />}
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
                <TaskProperties
                    task={task}
                    can={can}
                    update={update}
                    agents={agents}
                    people={people}
                    goals={goals}
                    projects={projects}
                    statuses={statuses}
                    priorities={priorities}
                />
            )}
            {task.agent?.description && (
                <div className="flex flex-col gap-1.5 rounded-xl border bg-card p-4 text-sm">
                    <h2 className="text-sm font-semibold">Sobre {agentName}</h2>
                    <p className="text-muted-foreground">{task.agent.description}</p>
                </div>
            )}
        </>
    );

    const panel = (
        <SidePanel
            tab={tab}
            onTab={setTab}
            onClose={() => togglePanel(false)}
            runs={runs}
            runId={runId}
            stepId={stepId}
            panelRun={panelRun}
            toolNames={toolNames}
            messages={messages}
            onOpenRun={openRun}
            onCloseRun={closeRun}
            details={details}
            agentWork={agentWork}
        />
    );

    return (
        <AppLayout
            bleed
            breadcrumbs={
                !task.is_conversation
                    ? [{ label: 'Tarefas', href: '/tasks' }, { label: task.ref }]
                    : someoneElses
                      ? [
                            { label: 'Agentes', href: '/agents' },
                            { label: agentName, href: task.assignee ? `/agents/${task.assignee.id}` : undefined },
                            { label: `Conversa de ${task.user}` },
                        ]
                      : [{ label: 'Agentes', href: '/agents' }, { label: agentName }]
            }
        >
            <Head title={task.is_conversation ? `Conversa com ${agentName}` : `${task.ref} · ${task.title}`} />

            <div className="flex min-w-0 flex-1 flex-col">
                <header className="flex shrink-0 items-center gap-3 border-b px-4 py-2.5 sm:px-6">
                    {task.is_conversation ? (
                        <>
                            <AgentAvatar name={agentName} className="size-8 rounded-lg text-xs" />
                            <div className="min-w-0 flex-1">
                                <h1 className="truncate text-sm font-semibold">{agentName}</h1>
                                <p className="truncate text-xs text-muted-foreground">
                                    {task.agent?.title ?? 'Assistente'}
                                    {working ? (
                                        <span className="text-status-running"> · a trabalhar</span>
                                    ) : elsewhere.length > 0 ? (
                                        <span className="text-status-running"> · a trabalhar noutro sítio</span>
                                    ) : (
                                        ' · uma só conversa contínua, com todo o histórico'
                                    )}
                                </p>
                            </div>
                        </>
                    ) : (
                        <div className="flex min-w-0 flex-1 items-center gap-2">
                            <TaskStatusIcon status={task.status} label={task.status_label} />
                            <span className="shrink-0 font-mono text-xs text-muted-foreground">{task.ref}</span>
                            <h1 className="min-w-0 truncate text-sm font-semibold">{task.title}</h1>
                            {task.priority !== 'normal' && (
                                <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">
                                    <PriorityIcon priority={task.priority} label={task.priority_label} withLabel />
                                </span>
                            )}
                            {task.kind === 'chat' && <ChatBadge />}
                        </div>
                    )}

                    <div className="flex shrink-0 items-center gap-1">
                        {has_journey && (
                            <Segmented
                                value={view}
                                onChange={openView}
                                options={[
                                    {
                                        value: 'conversation',
                                        label: (
                                            <>
                                                <MessageSquare />
                                                <span className="hidden sm:inline">Conversa</span>
                                            </>
                                        ),
                                    },
                                    {
                                        value: 'journey',
                                        label: (
                                            <>
                                                <Route />
                                                <span className="hidden sm:inline">Percurso</span>
                                            </>
                                        ),
                                        title: 'Quem teve o caso, cada passagem e o tempo de cada uma',
                                    },
                                ]}
                            />
                        )}
                        {task.is_conversation && task.assignee && (
                            <>
                                <Button variant="ghost" size="icon" className="size-8" asChild>
                                    <Link href={`/agents/${task.assignee.id}`} aria-label="Ver agente" title="Ver agente">
                                        <UserRound />
                                    </Link>
                                </Button>
                                {can.manage_agent && (
                                    <Button variant="ghost" size="icon" className="size-8" asChild>
                                        <Link href={`/agents/${task.assignee.id}/edit`} aria-label="Editar agente" title="Editar agente">
                                            <Pencil />
                                        </Link>
                                    </Button>
                                )}
                            </>
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            className={cn('size-8', panelOpen && 'bg-accent text-foreground')}
                            onClick={() => togglePanel(!panelOpen)}
                            aria-pressed={panelOpen}
                            aria-label={panelOpen ? 'Fechar painel lateral' : 'Abrir painel lateral'}
                            title={panelOpen ? 'Fechar painel lateral' : 'Abrir painel lateral'}
                        >
                            <PanelRight />
                        </Button>
                    </div>
                </header>

                <div className="min-h-0 flex-1 overflow-y-auto">
                    <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pt-4 pb-6 sm:px-6">
                        {!task.is_conversation && (task.description || task.parent) && (
                            <div className="flex flex-col gap-2 rounded-xl border bg-card px-4 py-3">
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
                                    <div className="text-sm text-foreground/90 [&>div>*:first-child]:mt-0 [&>div>*:last-child]:mb-0">
                                        <Markdown>{task.description}</Markdown>
                                    </div>
                                )}
                                {task.goal && (
                                    <Link href={`/tasks?view=all&goal=${task.goal.id}`} className="w-fit">
                                        <GoalChip goal={task.goal} />
                                    </Link>
                                )}
                            </div>
                        )}

                        {view === 'journey' ? (
                            journey ? (
                                <JourneyView journey={journey} taskId={task.id} />
                            ) : (
                                <div className="h-64 animate-pulse rounded-xl border bg-card" />
                            )
                        ) : (
                            <>
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

                                <div className="flex flex-col">
                                    {messages.length === 0 && !working && (
                                        <EmptyState
                                            icon={MessageSquare}
                                            title="Ainda sem mensagens"
                                            description={`Escreva abaixo para dar contexto ou instruções a ${agentName}.`}
                                        />
                                    )}

                                    <ChatThread messages={messages} toolCalls={toolCalls} thinking={thinking} viewer={auth.user?.name ?? null} onOpenRun={openRun} />

                                    {(working || stream) && (
                                        <WorkingBubble
                                            agent={working?.agent.name ?? agentName}
                                            runId={working?.id ?? stream?.run_id ?? null}
                                            live={stream}
                                            calls={toolCalls[working?.id ?? stream?.run_id ?? 0] ?? []}
                                            onOpenRun={openRun}
                                        />
                                    )}
                                    {task.is_conversation && !working && !stream && (
                                        <ElsewherePanel
                                            agent={agentName}
                                            active={elsewhere}
                                            done={elsewhereDone}
                                            reload={['agent_elsewhere', 'agent_elsewhere_done', 'sidebar_agents']}
                                        />
                                    )}
                                    <div ref={bottom} className="h-px" />
                                </div>
                            </>
                        )}
                    </div>
                </div>

                {view === 'conversation' && (
                    <div className="shrink-0">
                        <div className="mx-auto flex w-full max-w-3xl flex-col gap-2 px-4 pb-3 sm:px-6">
                            {task.status === 'waiting_human' ? (
                                <div className="flex gap-3 rounded-xl border border-status-warning/40 bg-[color-mix(in_oklch,var(--status-warning)_10%,var(--card))] px-4 py-3">
                                    <CircleHelp className="mt-0.5 size-4 shrink-0 text-status-warning" />
                                    <div className="min-w-0 space-y-1 text-sm">
                                        <p className="font-medium">{agentName} está à tua espera</p>
                                        {pendingCard ? (
                                            <p className="text-xs text-muted-foreground">
                                                Responda às perguntas no cartão acima, ou escreva abaixo a sua resposta. {agentName} continua logo a
                                                seguir.
                                            </p>
                                        ) : (
                                            <>
                                                {pendingQuestion && (
                                                    <p className="line-clamp-2 text-muted-foreground">{plainText(pendingQuestion.body)}</p>
                                                )}
                                                <p className="text-xs text-muted-foreground">Responda abaixo para a tarefa continuar.</p>
                                            </>
                                        )}
                                    </div>
                                </div>
                            ) : null}

                            {task.status === 'in_review' && task.kind === 'task' && can.update && (
                                <div className="flex flex-col gap-3 rounded-xl border border-status-success/40 bg-[color-mix(in_oklch,var(--status-success)_10%,var(--card))] px-4 py-3 sm:flex-row sm:items-center">
                                    <div className="min-w-0 flex-1 space-y-1 text-sm">
                                        <p className="font-medium">Pronta para rever</p>
                                        <p className="text-xs text-muted-foreground">
                                            Aceite para a fechar, ou escreva abaixo o que falta e ela volta para quem a fez.
                                        </p>
                                    </div>
                                    <Button size="sm" onClick={() => update({ status: 'done' })}>
                                        Aceitar
                                    </Button>
                                </div>
                            )}

                            {approvals.map((approval) => (
                                <ApprovalCard key={approval.id} approval={approval} />
                            ))}

                            {workflow?.decision && <WorkflowDecision decision={workflow.decision} />}

                            {can.reply ? (
                                <form
                                    onSubmit={send}
                                    {...drop.dropProps}
                                    className="relative rounded-2xl border bg-card shadow-sm focus-within:ring-[3px] focus-within:ring-ring/30"
                                >
                                    <DropOverlay show={drop.dragging} />
                                    <AttachmentTray
                                        items={files.items}
                                        onRemove={files.remove}
                                        notice={files.notice}
                                        onDismissNotice={files.dismissNotice}
                                    />
                                    <Textarea
                                        rows={2}
                                        value={form.data.body}
                                        onChange={(e) => form.setData('body', e.target.value)}
                                        onKeyDown={onKeyDown}
                                        onPaste={drop.onPaste}
                                        placeholder={
                                            form.data.mode === 'action'
                                                ? `Diga a ${agentName} o que executar agora…`
                                                : task.status === 'waiting_human'
                                                  ? 'Escreva a sua resposta…'
                                                  : `Escreva a ${agentName}…`
                                        }
                                        className="max-h-60 min-h-14 resize-none border-0 bg-transparent px-4 pt-3 shadow-none focus-visible:ring-0 dark:bg-transparent"
                                    />
                                    <div className="flex items-center gap-1.5 px-2 pb-2 sm:gap-2">
                                        <AttachButton extensions={attachment_limits.extensions} onFiles={files.add} />
                                        {attachment_limits.types.includes('audio') && (
                                            <RecordControl
                                                maxSeconds={attachment_limits.audio_max_seconds}
                                                onRecorded={(file) => files.add([file], 'recording')}
                                            />
                                        )}
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
                                        <span className="flex-1" />
                                        <Button
                                            type="submit"
                                            size="sm"
                                            className="rounded-lg"
                                            disabled={!canSend}
                                            aria-label={form.data.mode === 'action' ? 'Executar' : 'Enviar'}
                                            title={files.uploading ? 'À espera que os ficheiros acabem de carregar' : undefined}
                                        >
                                            {form.data.mode === 'action' ? <Zap /> : <Send />}
                                            <span className="hidden sm:inline">{form.data.mode === 'action' ? 'Executar' : 'Enviar'}</span>
                                        </Button>
                                    </div>
                                    {(form.errors.body || form.errors.mode || form.errors.attachment_ids) && (
                                        <div className="px-3 pb-2">
                                            <InputError message={form.errors.body ?? form.errors.mode ?? form.errors.attachment_ids} />
                                        </div>
                                    )}
                                </form>
                            ) : (
                                <div className="flex items-center gap-2 rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">
                                    <Lock className="size-4 shrink-0" />
                                    {someoneElses
                                        ? `Conversa de ${task.user} com ${agentName}. Pode lê-la, mas só ${task.user} escreve aqui.`
                                        : 'Só quem participa nesta tarefa ou responde pelo agente pode escrever aqui.'}
                                </div>
                            )}

                            <p className="flex flex-wrap items-center justify-center gap-x-3 gap-y-1 px-1 text-center text-[11px] text-muted-foreground">
                                {/* Fixed notice (realinhamento, decisão 26): said plainly, where it is read before writing. */}
                                {task.is_conversation && (
                                    <span className="inline-flex items-center gap-1">
                                        <Lock className="size-3" />
                                        As conversas podem ser consultadas pela direcção.
                                    </span>
                                )}
                                <span className="hidden sm:inline">
                                    {isClosed ? 'Escrever reabre a tarefa. ' : ''}Enter envia · Shift+Enter nova linha
                                </span>
                            </p>
                        </div>
                    </div>
                )}
            </div>

            {wide ? (
                panelOpen && <aside className="flex w-[26rem] shrink-0 flex-col border-l 2xl:w-[30rem]">{panel}</aside>
            ) : (
                <Sheet open={panelOpen} onOpenChange={setPanelOpen}>
                    <SheetContent side="right" showCloseButton={false} className="w-full gap-0 p-0 sm:max-w-md">
                        <SheetTitle className="sr-only">Painel da conversa</SheetTitle>
                        {panel}
                    </SheetContent>
                </Sheet>
            )}
        </AppLayout>
    );
}

/** The task's editable properties, in the side panel's "Detalhes". */
function TaskProperties({
    task,
    can,
    update,
    agents,
    people,
    goals,
    projects,
    statuses,
    priorities,
}: Pick<Props, 'task' | 'can' | 'agents' | 'people' | 'goals' | 'projects' | 'statuses' | 'priorities'> & {
    update: (data: Record<string, string | number | null>) => void;
}) {
    return (
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
            <Property label="Responsável">
                {can.update ? (
                    <InlineSelect
                        value={task.assignee ? `agent:${task.assignee.id}` : task.assignee_user ? `user:${task.assignee_user.id}` : NONE}
                        onChange={(value) =>
                            update(
                                value.startsWith('user:')
                                    ? { assignee_user_id: Number(value.slice(5)) }
                                    : { assignee_agent_id: value === NONE ? null : Number(value.slice(6)) },
                            )
                        }
                        options={[
                            { value: NONE, label: <span className="text-muted-foreground">Por atribuir</span> },
                            ...withCurrent(agents, task.assignee).map((agent) => ({
                                value: `agent:${agent.id}`,
                                label: <AgentOption name={agent.name} />,
                            })),
                            ...withCurrent(people, task.assignee_user).map((person) => ({
                                value: `user:${person.id}`,
                                label: (
                                    <span className="inline-flex items-center gap-1.5">
                                        <Monogram name={person.name} className="size-5 rounded-md text-[9px]" />
                                        {person.name}
                                    </span>
                                ),
                            })),
                        ]}
                    />
                ) : task.assignee ? (
                    <Link href={`/agents/${task.assignee.id}`} className="hover:underline">
                        <AgentOption name={task.assignee.name} />
                    </Link>
                ) : (
                    task.assignee_user?.name
                )}
            </Property>
            <Property label="Projecto">
                {can.update ? (
                    <InlineSelect
                        value={task.project ? String(task.project.id) : NONE}
                        onChange={(value) => update({ project_id: value === NONE ? null : Number(value) })}
                        options={[
                            { value: NONE, label: <span className="text-muted-foreground">Nenhum</span> },
                            ...withCurrent(projects, task.project).map((project) => ({
                                value: String(project.id),
                                label: project.name,
                            })),
                        ]}
                    />
                ) : (
                    task.project?.name
                )}
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
                    <Link href={task.source.href} className="hover:underline">
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

/** A pending action of this task's agent, decided right here. */
function ApprovalCard({ approval }: { approval: PendingApproval }) {
    const [rejecting, setRejecting] = useState(false);
    const form = useForm({ note: '' });
    const decide = (action: 'approve' | 'reject') =>
        form.post(`/approvals/${approval.id}/${action}`, { preserveScroll: true, only: [...reloadProps, 'flash'] });

    return (
        <div className="flex gap-3 rounded-xl border border-status-warning/40 bg-[color-mix(in_oklch,var(--status-warning)_10%,var(--card))] px-4 py-3">
            <Lock className="mt-0.5 size-4 shrink-0 text-status-warning" />
            <div className="min-w-0 flex-1 space-y-2 text-sm">
                <p className="font-medium">À espera de aprovação</p>
                <p className="break-words text-muted-foreground">{approvalTitle(approval.action_type, approval.payload, approval.summary)}</p>
                {approval.ceiling_reason && <p className="text-xs text-muted-foreground">Decide sempre uma pessoa: {approval.ceiling_reason}</p>}
                {approval.can_decide ? (
                    rejecting ? (
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Textarea
                                rows={1}
                                autoFocus
                                placeholder="Motivo da rejeição"
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                className="min-h-9 bg-background"
                            />
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    disabled={form.processing || form.data.note.trim() === ''}
                                    onClick={() => decide('reject')}
                                >
                                    Rejeitar
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setRejecting(false)}>
                                    Cancelar
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex gap-2">
                            <Button size="sm" disabled={form.processing} onClick={() => decide('approve')}>
                                Aprovar
                            </Button>
                            <Button size="sm" variant="outline" disabled={form.processing} onClick={() => setRejecting(true)}>
                                Rejeitar…
                            </Button>
                        </div>
                    )
                ) : (
                    <p className="text-xs text-muted-foreground">Quem decide é a pessoa responsável pela aprovação.</p>
                )}
                <InputError message={form.errors.note} />
            </div>
        </div>
    );
}

const stepTone = (status: string): Tone =>
    (
        ({ active: 'running', waiting_approval: 'warning', waiting: 'warning', done: 'success', blocked: 'danger', cancelled: 'idle' }) as Record<
            string,
            Tone
        >
    )[status] ?? 'idle';

const answerLabel: Record<string, string> = { yes: 'sim', no: 'não', approved: 'aprovado', rejected: 'rejeitado' };

function WorkflowProgress({ progress }: { progress: NonNullable<WorkflowInfo['progress']> }) {
    return (
        <Properties title="Fluxo">
            <div className="mb-2 flex items-center justify-between gap-2 text-sm">
                <Link href={`/settings/workflows?w=${progress.workflow.id}`} className="truncate font-medium hover:underline">
                    {progress.workflow.name}
                </Link>
                <StatusBadge tone={stepTone(progress.status === 'completed' ? 'done' : progress.status === 'running' ? 'active' : progress.status)}>
                    {progress.status_label}
                </StatusBadge>
            </div>
            <ol className="flex flex-col gap-1.5">
                {progress.steps.map((step) => (
                    <li key={step.id} className="flex items-start gap-2 text-xs">
                        <StatusDot tone={stepTone(step.status)} className="mt-1" />
                        <div className="min-w-0 flex-1">
                            <span className="text-foreground">{step.label}</span>
                            {step.item && <span className="text-muted-foreground"> · {step.item}</span>}
                            {step.answer && <span className="text-muted-foreground"> → {answerLabel[step.answer] ?? step.answer}</span>}
                            {step.task && (
                                <Link href={`/tasks/${step.task.id}`} className="ml-1 font-mono text-muted-foreground hover:underline">
                                    {step.task.ref}
                                </Link>
                            )}
                        </div>
                    </li>
                ))}
            </ol>
        </Properties>
    );
}

function WorkflowDecision({ decision }: { decision: NonNullable<WorkflowInfo['decision']> }) {
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <div className="flex flex-col gap-3 rounded-xl border border-status-warning/40 bg-status-warning/8 p-4">
            <div className="space-y-1 text-sm">
                <p className="font-medium">{decision.question}</p>
                <p className="text-xs text-muted-foreground">Passo do fluxo «{decision.workflow}». O fluxo segue com a sua resposta.</p>
            </div>
            {decision.can_decide ? (
                <>
                    <Textarea rows={2} value={note} placeholder="Nota (opcional)" onChange={(event) => setNote(event.target.value)} />
                    <div className="flex flex-wrap gap-2">
                        {decision.options.map((option, index) => (
                            <Button
                                key={option.value}
                                size="sm"
                                variant={index === 0 ? 'default' : 'outline'}
                                disabled={busy}
                                onClick={() => {
                                    setBusy(true);
                                    router.post(
                                        `/workflow-steps/${decision.step_id}/decide`,
                                        { decision: option.value, note: note || null },
                                        { preserveScroll: true, onFinish: () => setBusy(false) },
                                    );
                                }}
                            >
                                {option.label}
                            </Button>
                        ))}
                    </div>
                </>
            ) : (
                <p className="text-xs text-muted-foreground">Só a pessoa responsável por esta tarefa decide.</p>
            )}
        </div>
    );
}
