import { Link, router, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, ArrowLeft, ArrowUpRight, ChevronDown, CircleDot, Info, type LucideIcon, Paperclip, Search, X } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ApprovalCard, ApprovalList } from '@/Components/ApprovalCard';
import { approvalTitle } from '@/lib/approvals';
import { MessageAttachments } from '@/Components/attachments/MessageAttachments';
import { Markdown } from '@/Components/Markdown';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { runTone, StatusDot } from '@/Components/Status';
import { RunRequest, RunTimeline, type Step } from '@/Components/runs/RunTimeline';
import { useLive } from '@/hooks/useLive';
import { ago, dateTime, duration, number, runTitle, time, usd, usdPrecise } from '@/lib/format';
import { cn } from '@/lib/utils';
import { TaskStatusIcon, type TaskSummary } from '@/Pages/Tasks/Index';
import type { ApprovalSummary, RunSummary, SharedProps } from '@/types';

import type { ChatMessage, OpenRun } from './Chat';

/*
 * The right-hand panel of a conversation, in the manner of the Claude desktop
 * side panel: a strip of tabs on top, one thing at a time below. "Execuções"
 * lists this conversation's runs and opens one in place, so following what an
 * agent did never leaves the chat (docs/DECISOES.md, "Página de conversa").
 */

export type PanelTab = 'details' | 'tasks' | 'runs' | 'files';

/** The person's work with the agent of their conversation (docs/DECISOES.md, "Conversas a partir dos agentes"). */
export interface AgentWork {
    tasks: TaskSummary[];
    approvals: (ApprovalSummary & { task_id: number | null })[];
}

export interface PanelRun {
    run: RunSummary;
    steps: Step[];
    approvals: ApprovalSummary[];
}

const tabs: { value: PanelTab; label: string; icon: LucideIcon }[] = [
    { value: 'details', label: 'Detalhes', icon: Info },
    { value: 'tasks', label: 'Tarefas', icon: CircleDot },
    { value: 'runs', label: 'Execuções', icon: Activity },
    { value: 'files', label: 'Ficheiros', icon: Paperclip },
];

export function SidePanel({
    tab,
    onTab,
    onClose,
    runs,
    runId,
    stepId,
    panelRun,
    toolNames,
    messages,
    onOpenRun,
    onCloseRun,
    details,
    agentWork = null,
    className,
}: {
    tab: PanelTab;
    onTab: (tab: PanelTab) => void;
    onClose: () => void;
    runs: RunSummary[];
    runId: number | null;
    stepId: number | null;
    panelRun: PanelRun | null;
    toolNames: Record<string, string>;
    messages: ChatMessage[];
    onOpenRun: OpenRun;
    onCloseRun: () => void;
    /** Null in a conversation: the header already says who, with whom and since when. */
    details: ReactNode | null;
    agentWork?: AgentWork | null;
    className?: string;
}) {
    const files = messages.filter((message) => message.attachments.length > 0);
    const open = agentWork?.tasks.filter((task) => task.status !== 'done' && task.status !== 'cancelled').length ?? 0;
    const counts: Partial<Record<PanelTab, number>> = {
        tasks: open + (agentWork?.approvals.length ?? 0),
        runs: runs.length,
        files: files.reduce((n, m) => n + m.attachments.length, 0),
    };
    const shown = tabs.filter(({ value }) => (value !== 'tasks' || agentWork !== null) && (value !== 'details' || details !== null));

    return (
        <div className={cn('flex h-full min-h-0 flex-col bg-sidebar/40', className)}>
            <div className="flex h-11 shrink-0 items-center gap-1 border-b px-2">
                <div role="tablist" aria-label="Painel" className="flex min-w-0 flex-1 items-center gap-0.5">
                    {shown.map(({ value, label, icon: Icon }) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            aria-selected={tab === value}
                            onClick={() => onTab(value)}
                            className={cn(
                                'inline-flex h-7 items-center gap-1.5 rounded-md px-2.5 text-xs font-medium transition-colors',
                                tab === value ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                            )}
                        >
                            {/* The tabs fit the narrow panel by their names alone; the icons come back when there is room. */}
                            <Icon className="hidden size-3.5 2xl:block" />
                            {label}
                            {!!counts[value] && <span className="text-[10px] text-muted-foreground tabular-nums">{counts[value]}</span>}
                        </button>
                    ))}
                </div>
                <button
                    type="button"
                    onClick={onClose}
                    aria-label="Fechar painel"
                    title="Fechar painel"
                    className="inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent/60 hover:text-foreground"
                >
                    <X className="size-4" />
                </button>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {tab === 'details' && details !== null && <div className="flex flex-col gap-6 p-4">{details}</div>}
                {tab === 'runs' &&
                    (runId !== null ? (
                        <RunView runId={runId} stepId={stepId} panelRun={panelRun} toolNames={toolNames} onBack={onCloseRun} />
                    ) : (
                        <RunList runs={runs} toolNames={toolNames} onOpenRun={onOpenRun} />
                    ))}
                {tab === 'tasks' && agentWork && <WorkList work={agentWork} />}
                {tab === 'files' && <FilesList messages={files} />}
            </div>
        </div>
    );
}

function WorkList({ work }: { work: AgentWork }) {
    if (work.tasks.length === 0 && work.approvals.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
                <CircleDot className="size-5" />
                Ainda não tem tarefas com este agente. Peça trabalho aqui na conversa e a tarefa aparece nesta lista.
            </div>
        );
    }

    return (
        <div className="flex flex-col gap-4 p-2">
            {work.approvals.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h3 className="px-2 pt-1 text-xs font-medium text-muted-foreground">Aprovações à espera · {work.approvals.length}</h3>
                    {/* A short row each: they are decided where they open (the task, or Aprovações), with the whole context. */}
                    <ul className="divide-y overflow-hidden rounded-xl border bg-card">
                        {work.approvals.map((approval) => (
                            <li key={approval.id}>
                                <Link
                                    href={approval.task_id !== null ? `/tasks/${approval.task_id}` : '/approvals'}
                                    className="flex items-start gap-2 px-2.5 py-2 transition-colors hover:bg-accent/50"
                                >
                                    <AlertTriangle className="mt-0.5 size-3.5 shrink-0 text-status-warning" />
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 text-[13px] leading-snug">
                                            {approvalTitle(approval.action_type, approval.payload, approval.action_summary)}
                                        </span>
                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                            {approval.can_decide ? 'Espera a sua decisão' : 'À espera de decisão'} · {ago(approval.created_at)}
                                        </span>
                                    </span>
                                    <ArrowUpRight className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
            {work.tasks.length > 0 && (
                <section className="flex flex-col gap-2">
                    <h3 className="flex items-center justify-between px-2 pt-1 text-xs font-medium text-muted-foreground">
                        As suas tarefas com este agente
                        <Link href="/tasks" className="font-normal hover:text-foreground">
                            Todas as tarefas
                        </Link>
                    </h3>
                    {/* One line each, as in the chat's side panel of Claude: the task page has the rest. */}
                    <ul className="flex flex-col">
                        {work.tasks.map((task) => (
                            <li key={task.id}>
                                <Link
                                    href={`/tasks/${task.id}`}
                                    title={`${task.title} · ${task.status_label}`}
                                    className="flex items-center gap-2 rounded-md px-2 py-1.5 transition-colors hover:bg-accent/60"
                                >
                                    <TaskStatusIcon status={task.status} label={task.status_label} className="size-3.5" />
                                    <span
                                        className={cn(
                                            'min-w-0 flex-1 truncate text-[13px]',
                                            (task.status === 'done' || task.status === 'cancelled') && 'text-muted-foreground',
                                        )}
                                    >
                                        {task.title}
                                    </span>
                                    <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                        {ago(task.last_activity_at ?? task.created_at)}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

function RunList({ runs, toolNames, onOpenRun }: { runs: RunSummary[]; toolNames: Record<string, string>; onOpenRun: OpenRun }) {
    if (runs.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
                <Activity className="size-5" />O agente ainda não trabalhou nesta conversa.
            </div>
        );
    }

    const spent = runs.reduce((sum, run) => sum + run.cost_usd, 0);

    return (
        <div className="flex flex-col">
            <div className="flex items-center justify-between px-4 pt-3 pb-1.5 text-xs text-muted-foreground">
                <span>
                    {runs.length} {runs.length === 1 ? 'execução' : 'execuções'}
                </span>
                <span className="tabular-nums" title="Soma das execuções mostradas">
                    {usd(spent)}
                </span>
            </div>
            {/* One line each: what it was and when. Status, duration and cost are in the tooltip and the run itself. */}
            <ul className="flex flex-col px-2 pb-3">
                {runs.map((run) => (
                    <li key={run.id}>
                        <button
                            type="button"
                            onClick={() => onOpenRun(run.id)}
                            title={[
                                run.status_label,
                                run.model_label,
                                run.duration_ms !== null ? duration(run.duration_ms) : null,
                                usd(run.cost_usd),
                                dateTime(run.created_at),
                            ]
                                .filter(Boolean)
                                .join(' · ')}
                            className="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-left transition-colors hover:bg-accent/60"
                        >
                            <StatusDot tone={runTone(run.status)} />
                            <span className="min-w-0 flex-1 truncate text-[13px]">{run.title ?? runTitle(run.input, toolNames)}</span>
                            <span className="shrink-0 text-[11px] text-muted-foreground tabular-nums">
                                {new Date(run.created_at).toDateString() === new Date().toDateString() ? time(run.created_at) : ago(run.created_at)}
                            </span>
                        </button>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function RunView({
    runId,
    stepId,
    panelRun,
    toolNames,
    onBack,
}: {
    runId: number;
    stepId: number | null;
    panelRun: PanelRun | null;
    toolNames: Record<string, string>;
    onBack: () => void;
}) {
    const { tenant } = usePage<SharedProps>().props;
    const loaded = panelRun?.run.id === runId ? panelRun : null;
    const run = loaded?.run;
    const finished = run ? ['completed', 'failed', 'cancelled'].includes(run.status) : true;
    const [liveSteps, setLiveSteps] = useState<Step[]>([]);

    useEffect(() => setLiveSteps([]), [runId]);

    useLive<{ step?: Step }>(
        tenant && loaded ? `tenant.${tenant.id}.run.${runId}` : null,
        ['AgentRunStepAdded', 'AgentRunStarted', 'AgentRunFinished', 'ApprovalDecided'],
        (event, payload) => {
            if (event === 'AgentRunStepAdded' && payload.step) {
                setLiveSteps((current) => [...current, payload.step as Step]);
            } else {
                router.reload({ only: ['panel_run'], onSuccess: () => setLiveSteps([]) });
            }
        },
        { only: ['panel_run'], poll: !!loaded && !finished },
    );

    const known = new Set(loaded?.steps.map((step) => step.id) ?? []);
    const steps = [...(loaded?.steps ?? []), ...liveSteps.filter((step) => !known.has(step.id))].sort((a, b) => a.seq - b.seq);

    return (
        <div className="flex flex-col">
            <div className="sticky top-0 z-10 flex items-center gap-1 border-b bg-background/95 px-2 py-1.5 backdrop-blur">
                <button
                    type="button"
                    onClick={onBack}
                    className="inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground hover:bg-accent/60 hover:text-foreground"
                >
                    <ArrowLeft className="size-3.5" />
                    Execuções
                </button>
                <span className="flex-1" />
                <Link
                    href={`/settings/runs/${runId}`}
                    className="inline-flex h-7 items-center gap-1 rounded-md px-2 text-xs text-muted-foreground hover:bg-accent/60 hover:text-foreground"
                    title="Abrir a execução na sua página"
                >
                    Página inteira
                    <ArrowUpRight className="size-3.5" />
                </Link>
            </div>

            {!run ? (
                <div className="flex flex-col gap-3 p-4">
                    <div className="h-10 animate-pulse rounded-lg bg-muted" />
                    <div className="h-24 animate-pulse rounded-lg bg-muted" />
                    <div className="h-40 animate-pulse rounded-lg bg-muted" />
                </div>
            ) : (
                <div className="flex flex-col gap-5 p-4">
                    <div className="flex items-start gap-3">
                        <AgentAvatar name={run.agent.name} className="size-8 rounded-lg text-[10px]" />
                        <div className="min-w-0 flex-1">
                            <p className="line-clamp-2 text-sm font-semibold">{run.title ?? runTitle(run.input, toolNames)}</p>
                            <p className="truncate text-xs text-muted-foreground">
                                {run.agent.name} · {run.trigger_label} · {dateTime(run.created_at)}
                            </p>
                            {run.model_label && (
                                <p className="truncate text-xs text-muted-foreground" title={`${run.provider_label ?? ''} · ${run.model}`}>
                                    Modelo: {run.model_label}
                                </p>
                            )}
                        </div>
                        <RunStatusBadge status={run.status} label={run.status_label} />
                    </div>

                    <dl className="grid grid-cols-3 gap-4 text-xs">
                        <Metric label="Duração" value={run.duration_ms !== null ? duration(run.duration_ms) : '—'} />
                        <Metric label="Custo" value={usdPrecise(run.cost_usd)} />
                        <Metric label="Tokens" value={`${number(run.input_tokens)} / ${number(run.output_tokens)}`} title="Entrada / saída" />
                    </dl>

                    <details className="group">
                        <summary className="flex cursor-pointer list-none items-center gap-2 py-1 text-xs font-medium text-muted-foreground hover:text-foreground [&::-webkit-details-marker]:hidden">
                            Pedido
                            <ChevronDown className="ml-auto size-3.5 transition-transform group-open:rotate-180" />
                        </summary>
                        <div className="pt-1 text-sm">
                            <RunRequest input={run.input} plain />
                        </div>
                    </details>

                    <section className="flex flex-col gap-3">
                        <h3 className="flex items-center justify-between text-xs font-medium tracking-widest text-muted-foreground uppercase">
                            Linha temporal
                            <span className="tracking-normal normal-case tabular-nums">{steps.length} passos</span>
                        </h3>
                        <RunTimeline steps={steps} finished={finished} output={run.output} toolNames={toolNames} focusStepId={stepId} />
                    </section>

                    {run.error && (
                        <div className="flex gap-2 rounded-lg border border-status-danger/30 bg-status-danger/10 px-3 py-2 text-sm text-status-danger">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <span className="min-w-0 break-words whitespace-pre-wrap">{run.error}</span>
                        </div>
                    )}

                    {run.output && (
                        <details className="group">
                            <summary className="flex cursor-pointer list-none items-center gap-2 py-1 text-xs font-medium text-muted-foreground hover:text-foreground [&::-webkit-details-marker]:hidden">
                                Resposta final
                                <ChevronDown className="ml-auto size-3.5 transition-transform group-open:rotate-180" />
                            </summary>
                            <div className="pt-1 text-sm">
                                <Markdown>{run.output}</Markdown>
                            </div>
                        </details>
                    )}

                    {loaded.approvals.length > 0 && (
                        <section className="flex flex-col gap-3">
                            <h3 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Aprovações</h3>
                            <ApprovalList>
                                {loaded.approvals.map((approval) => (
                                    <ApprovalCard key={approval.id} approval={approval} />
                                ))}
                            </ApprovalList>
                        </section>
                    )}
                </div>
            )}
        </div>
    );
}

function Metric({ label, value, title }: { label: string; value: string; title?: string }) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5" title={title}>
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="font-medium tabular-nums">{value}</dd>
        </div>
    );
}

function FilesList({ messages }: { messages: ChatMessage[] }) {
    const [query, setQuery] = useState('');

    if (messages.length === 0) {
        return (
            <div className="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-muted-foreground">
                <Paperclip className="size-5" />
                Ainda não há ficheiros nesta conversa.
            </div>
        );
    }

    // By file name, type or who sent it, without accents ("relatorio" finds "Relatório").
    const fold = (text: string) => text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();
    const words = fold(query).split(/\s+/).filter(Boolean);
    const found = [...messages]
        .reverse()
        .map((message) => ({
            message,
            attachments: message.attachments.filter((file) =>
                words.every((word) => fold(`${file.name} ${file.extension} ${message.author}`).includes(word)),
            ),
        }))
        .filter(({ attachments }) => attachments.length > 0);

    return (
        <div className="flex flex-col">
            <div className="sticky top-0 z-10 border-b bg-background/95 p-2 backdrop-blur">
                <label className="flex h-8 items-center gap-2 rounded-md border bg-card px-2.5 text-sm focus-within:ring-2 focus-within:ring-ring/50">
                    <Search className="size-3.5 shrink-0 text-muted-foreground" />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Procurar ficheiros"
                        aria-label="Procurar ficheiros"
                        className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
                    />
                </label>
            </div>
            {found.length === 0 ? (
                <p className="px-4 py-8 text-center text-sm text-muted-foreground">Nenhum ficheiro com «{query.trim()}».</p>
            ) : (
                <div className="flex flex-col gap-4 p-3">
                    {found.map(({ message, attachments }) => (
                        <div key={message.id} className="flex flex-col gap-1.5">
                            <p className="text-[11px] text-muted-foreground">
                                <span className="font-medium text-foreground/80">{message.author}</span> · {dateTime(message.created_at)}
                            </p>
                            <MessageAttachments attachments={attachments} />
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
