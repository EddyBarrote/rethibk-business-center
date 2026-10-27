import { Link } from '@inertiajs/react';
import {
    ArrowDownLeft,
    ArrowUpRight,
    Check,
    ChevronDown,
    ChevronRight,
    ExternalLink,
    FileText,
    Loader2,
    Lock,
    Mail,
    Wrench,
    X,
    Zap,
} from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { MessageAttachments } from '@/Components/attachments/MessageAttachments';
import { Monogram } from '@/Components/Blocks';
import { EmailCards, type ProposedEmail } from '@/Components/tasks/EmailCard';
import { type AgentQuestions, QuestionCard } from '@/Components/tasks/QuestionCard';
import { Markdown } from '@/Components/Markdown';
import { StatusDot } from '@/Components/Status';
import type { MessageAttachment } from '@/lib/attachments';
import { date, dateTime, duration, time } from '@/lib/format';
import { cn } from '@/lib/utils';

/*
 * The conversation as a chat (docs/DECISOES.md, "Página de conversa"): people
 * on the right, agents on the left, every message with its time, and the
 * tools an agent called folded under its answer.
 */

export interface ChatMessage {
    id: number;
    author_type: 'user' | 'agent' | 'system' | 'platform_admin';
    author: string;
    author_id: number | null;
    kind: 'message' | 'action' | 'event' | 'report' | 'handoff';
    body: string;
    attachments: MessageAttachment[];
    run_id: number | null;
    created_at: string;
    /** The agent's questions on this message, answered on the card. */
    question?: AgentQuestions | null;
    /** A message between agents (docs/DECISOES.md, "Mensagens entre agentes"). */
    handoff?: Handoff | null;
    /** The email the person brought in from the inbox (docs/DECISOES.md, "Falar com um agente sobre um email"). */
    email?: { id: number; subject: string; from: string | null } | null;
}

export interface Handoff {
    /** Sent from this conversation, or arrived in it. */
    direction: 'out' | 'in';
    /** The request passed to the colleague, or its answer coming back. */
    role: 'request' | 'answer';
    /** The agent on the other side. */
    agent: string | null;
    agent_id: number | null;
    status: 'working' | 'answered' | 'failed';
    /** The other conversation, when the viewer may open it. */
    other_task_id: number | null;
}

export interface ToolCall {
    step_id: number;
    key: string;
    name: string;
    status: 'running' | 'approval' | 'ok' | 'error' | 'rejected';
    duration_ms: number | null;
    /** Where to see what the tool made (a draft, a report, a task), when it made something. */
    link?: { label: string; href: string } | null;
}

/** A turn's reasoning summary and how long the model thought (docs/DECISOES.md, "Raciocínio na conversa"). */
export interface Thought {
    text: string;
    ms: number;
}

/** The agent's turn as it streams in (TaskReplyStreaming). */
export interface LiveReply {
    run_id: number;
    text: string;
    /** What it is doing, in Portuguese («A pesquisar na internet»), while a tool runs. */
    tool: string | null;
    reasoning: string;
    /** Still thinking: the reasoning shows live under «A pensar…». */
    thinking: boolean;
    thought_ms: number;
}

/** Opens a run (and optionally one of its steps) in the side panel. */
export type OpenRun = (runId: number, stepId?: number) => void;

const dayKey = (value: string) => new Date(value).toDateString();

function dayLabel(value: string) {
    const day = new Date(value);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    if (day.toDateString() === today.toDateString()) {
        return 'Hoje';
    }
    if (day.toDateString() === yesterday.toDateString()) {
        return 'Ontem';
    }

    return date(value);
}

const isPerson = (message: ChatMessage) => message.author_type === 'user' || message.author_type === 'platform_admin';

/** A run's tools appear once, under the first agent message that run wrote. */
export function ChatThread({
    messages,
    toolCalls,
    thinking = {},
    emails = {},
    viewer,
    onOpenRun,
}: {
    messages: ChatMessage[];
    toolCalls: Record<number, ToolCall[]>;
    thinking?: Record<number, Thought>;
    /** The emails each turn proposed, shown as email cards (docs/DECISOES.md, "Email proposto na conversa"). */
    emails?: Record<number, ProposedEmail[]>;
    viewer: string | null;
    onOpenRun: OpenRun;
}) {
    const toolsShown = new Set<number>();

    return (
        <div className="flex flex-col">
            {messages.map((message, index) => {
                const previous = messages[index - 1];
                const newDay = !previous || dayKey(previous.created_at) !== dayKey(message.created_at);
                // Messages in a row from the same author read as one block: the name and avatar show once.
                const continued =
                    !newDay &&
                    previous.kind !== 'event' &&
                    previous.kind !== 'report' &&
                    previous.kind !== 'handoff' &&
                    previous.author === message.author &&
                    previous.author_type === message.author_type &&
                    new Date(message.created_at).getTime() - new Date(previous.created_at).getTime() < 5 * 60_000;

                let tools: ToolCall[] = [];
                let thought: Thought | null = null;
                let proposed: ProposedEmail[] = [];
                if (message.author_type === 'agent' && message.run_id && !toolsShown.has(message.run_id)) {
                    toolsShown.add(message.run_id);
                    tools = toolCalls[message.run_id] ?? [];
                    thought = thinking[message.run_id] ?? null;
                    proposed = emails[message.run_id] ?? [];
                }

                return (
                    <div key={message.id} className="flex flex-col">
                        {newDay && <DaySeparator label={dayLabel(message.created_at)} />}
                        {message.kind === 'handoff' && message.handoff ? (
                            <HandoffRow message={message} handoff={message.handoff} />
                        ) : message.kind === 'event' ? (
                            <EventLine message={message} onOpenRun={onOpenRun} />
                        ) : message.kind === 'report' ? (
                            <ReportCard message={message} onOpenRun={onOpenRun} />
                        ) : isPerson(message) ? (
                            <PersonBubble message={message} continued={continued} own={viewer === message.author} />
                        ) : (
                            <AgentBubble
                                message={message}
                                continued={continued}
                                tools={tools}
                                thought={thought}
                                emails={proposed}
                                onOpenRun={onOpenRun}
                            />
                        )}
                    </div>
                );
            })}
        </div>
    );
}

function DaySeparator({ label }: { label: string }) {
    return (
        <div className="sticky top-0 z-[1] flex justify-center py-3">
            <span className="rounded-full border bg-background/90 px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground shadow-xs backdrop-blur">
                {label}
            </span>
        </div>
    );
}

function Time({ value, className }: { value: string; className?: string }) {
    return (
        <time dateTime={value} title={dateTime(value)} className={cn('text-[11px] text-muted-foreground tabular-nums', className)}>
            {time(value)}
        </time>
    );
}

function ActionBadge() {
    return (
        <span className="inline-flex h-5 items-center gap-1 rounded-full bg-status-warning/15 px-2 text-[11px] font-medium text-warning-strong">
            <Zap className="size-3" />
            Acção directa
        </span>
    );
}

function PersonBubble({ message, continued, own }: { message: ChatMessage; continued: boolean; own: boolean }) {
    return (
        <div className={cn('group flex justify-end gap-2.5 pl-10 sm:pl-16', continued ? 'mt-1' : 'mt-4')}>
            <div className="flex min-w-0 flex-col items-end gap-1">
                {!continued && (
                    <div className="flex items-center gap-2 px-1">
                        {message.kind === 'action' && <ActionBadge />}
                        {!own && <span className="text-xs font-medium text-foreground/80">{message.author}</span>}
                    </div>
                )}
                {message.body !== '' && (
                    <div className="max-w-full rounded-2xl rounded-tr-md bg-muted px-4 py-2.5 text-sm break-words dark:bg-muted/70 [&_.prose]:text-sm [&>div>*:first-child]:mt-0 [&>div>*:last-child]:mb-0">
                        <Markdown>{message.body}</Markdown>
                    </div>
                )}
                {message.email && (
                    <Link
                        href={`/inbox/${message.email.id}`}
                        className="flex max-w-xs items-center gap-2 rounded-lg border bg-card px-3 py-2 text-left text-xs hover:bg-accent"
                        title="Abrir o email"
                    >
                        <Mail className="size-4 shrink-0 text-muted-foreground" />
                        <span className="min-w-0">
                            <span className="block truncate font-medium">{message.email.subject}</span>
                            {message.email.from && <span className="block truncate text-muted-foreground">{message.email.from}</span>}
                        </span>
                    </Link>
                )}
                {message.attachments.length > 0 && <MessageAttachments attachments={message.attachments} className="justify-end" />}
                <Time value={message.created_at} className="px-1" />
            </div>
            {!continued ? (
                <Monogram name={message.author} className="mt-0.5 size-7 shrink-0 rounded-full text-[10px]" />
            ) : (
                <span className="w-7 shrink-0" />
            )}
        </div>
    );
}

function AgentBubble({
    message,
    continued,
    tools,
    thought,
    emails,
    onOpenRun,
}: {
    message: ChatMessage;
    continued: boolean;
    tools: ToolCall[];
    thought: Thought | null;
    emails: ProposedEmail[];
    onOpenRun: OpenRun;
}) {
    return (
        <div className={cn('flex gap-2.5', continued ? 'mt-1' : 'mt-4')}>
            {!continued ? (
                <AgentAvatar name={message.author} className="mt-0.5 size-9 shrink-0 rounded-xl text-[11px]" />
            ) : (
                <span className="w-9 shrink-0" />
            )}
            <div className="flex min-w-0 flex-1 flex-col items-start gap-1">
                {!continued && (
                    <div className="flex items-center gap-2 px-1">
                        <span className="text-xs font-medium text-foreground/80">{message.author}</span>
                        {message.kind === 'action' && <ActionBadge />}
                    </div>
                )}
                {thought && thought.text !== '' && <ThoughtToggle thought={thought} />}
                {message.body !== '' && (
                    // As in Claude, an agent's reply is not a bubble: it takes the chat's width, so tables and formulas fit.
                    <Markdown className="w-full px-1 py-0.5">{message.body}</Markdown>
                )}
                {message.attachments.length > 0 && <MessageAttachments attachments={message.attachments} />}
                <EmailCards emails={emails} />
                {message.question && <QuestionCard question={message.question} />}
                <ResultLinks calls={tools} shown={emails.map((email) => `/inbox/${email.id}`)} />
                <div className="flex w-full flex-col items-start gap-1">
                    {tools.length > 0 && message.run_id && <ToolCalls runId={message.run_id} calls={tools} onOpenRun={onOpenRun} />}
                    <div className="flex items-center gap-2 px-1">
                        <Time value={message.created_at} />
                    </div>
                </div>
            </div>
        </div>
    );
}

export function RunButton({ runId, onOpenRun, children }: { runId: number; onOpenRun: OpenRun; children?: ReactNode }) {
    return (
        <button
            type="button"
            onClick={() => onOpenRun(runId)}
            className="text-[11px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
        >
            {children ?? 'Ver o passo a passo'}
        </button>
    );
}

export function ToolStatusIcon({ status, className }: { status: ToolCall['status']; className?: string }) {
    const base = cn('size-3.5 shrink-0', className);

    switch (status) {
        case 'ok':
            return <Check className={cn(base, 'text-status-success')} />;
        case 'error':
            return <X className={cn(base, 'text-status-danger')} />;
        case 'rejected':
            return <X className={cn(base, 'text-muted-foreground')} />;
        case 'approval':
            return <Lock className={cn(base, 'text-status-warning')} />;
        default:
            return <Loader2 className={cn(base, 'animate-spin text-status-running')} />;
    }
}

const statusWord: Record<ToolCall['status'], string> = {
    ok: 'concluída',
    error: 'falhou',
    rejected: 'rejeitada',
    approval: 'à espera de aprovação',
    running: 'a correr',
};

/**
 * The tools of one turn, folded to one line under the answer: open it to see
 * each call, and click a call to see it in the run, in the side panel.
 */
export function ToolCalls({ runId, calls, onOpenRun, live = false }: { runId: number; calls: ToolCall[]; onOpenRun: OpenRun; live?: boolean }) {
    const [open, setOpen] = useState(false);
    const box = useRef<HTMLDivElement>(null);
    const failed = calls.filter((call) => call.status === 'error').length;
    const waiting = calls.some((call) => call.status === 'approval');
    const names = [...new Set(calls.map((call) => call.name))];

    // Opened at the bottom of the chat, the list would unfold under the composer.
    useEffect(() => {
        if (open) {
            box.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }, [open]);

    return (
        <div ref={box} className="w-full max-w-md overflow-hidden rounded-xl border bg-background/60 text-xs">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="flex w-full items-center gap-2 px-3 py-1.5 text-left text-muted-foreground transition-colors hover:bg-accent/50 hover:text-foreground"
            >
                {live ? <Loader2 className="size-3.5 shrink-0 animate-spin text-status-running" /> : <Wrench className="size-3.5 shrink-0" />}
                <span className="shrink-0 font-medium text-foreground/80">
                    {calls.length} {calls.length === 1 ? 'ferramenta' : 'ferramentas'}
                </span>
                <span className="min-w-0 flex-1 truncate">
                    {names.slice(0, 2).join(', ')}
                    {names.length > 2 && ` +${names.length - 2}`}
                </span>
                {failed > 0 && <span className="shrink-0 text-status-danger">{failed} falhou</span>}
                {waiting && <Lock className="size-3 shrink-0 text-status-warning" />}
                <ChevronDown className={cn('size-3.5 shrink-0 transition-transform', open && 'rotate-180')} />
            </button>
            {open && (
                <div className="border-t">
                    <ul className="flex flex-col py-1">
                        {calls.map((call) => (
                            <li key={call.step_id}>
                                <button
                                    type="button"
                                    onClick={() => onOpenRun(runId, call.step_id)}
                                    title={`${call.key} · ${statusWord[call.status]}`}
                                    className="flex w-full items-center gap-2 px-3 py-1 text-left transition-colors hover:bg-accent/50"
                                >
                                    <ToolStatusIcon status={call.status} />
                                    <span className="min-w-0 flex-1 truncate text-foreground/90">{call.name}</span>
                                    {call.duration_ms !== null && (
                                        <span className="shrink-0 text-muted-foreground tabular-nums">{duration(call.duration_ms)}</span>
                                    )}
                                </button>
                            </li>
                        ))}
                    </ul>
                    <div className="border-t px-3 py-1.5">
                        <RunButton runId={runId} onOpenRun={onOpenRun} />
                    </div>
                </div>
            )}
        </div>
    );
}

/**
 * The agent working on this turn, as in Claude: its reasoning live while it
 * thinks, folded to «Pensou durante…» once it answers or uses a tool, what it
 * has written so far, and one quiet line saying what it is doing.
 */
export function WorkingBubble({
    agent,
    runId,
    live,
    calls,
    onOpenRun,
}: {
    agent: string;
    runId: number | null;
    live: LiveReply | null;
    calls: ToolCall[];
    onOpenRun: OpenRun;
}) {
    const text = live?.text.trim() ? live.text : null;
    const reasoning = live?.reasoning.trim() ? live.reasoning : null;
    const thinking = !!live?.thinking && reasoning !== null;

    return (
        <div className="mt-4 flex gap-2.5">
            <span className="relative mt-0.5 h-fit shrink-0">
                <AgentAvatar name={agent} className="size-9 rounded-xl text-[11px]" />
                <StatusDot tone="running" className="absolute -right-0.5 -bottom-0.5" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col items-start gap-1">
                <div className="flex items-center gap-2 px-1">
                    <span className="text-xs font-medium text-foreground/80">{agent}</span>
                </div>
                {reasoning && !thinking && <ThoughtToggle thought={{ text: reasoning, ms: live?.thought_ms ?? 0 }} />}
                {text && <Markdown className="w-full px-1 py-0.5">{text}</Markdown>}
                <Working label={live?.tool ?? null} />
                {thinking && <LiveReasoning text={reasoning} />}
                {runId && calls.length > 0 && <ToolCalls runId={runId} calls={calls} onOpenRun={onOpenRun} live />}
            </div>
        </div>
    );
}

/** One quiet line while the agent works, as in Claude: a turning spark and a shimmer. */
function Working({ label }: { label: string | null }) {
    return (
        <div className="flex items-center gap-1.5 px-1 py-0.5 text-sm" role="status" aria-live="polite">
            <svg viewBox="0 0 24 24" className="thinking-spark size-3.5 shrink-0 text-primary" aria-hidden="true">
                <path
                    fill="currentColor"
                    d="M12 2c.4 3.9 1.3 6.2 2.8 7.2C16.3 10.3 18.6 11 22 12c-3.4 1-5.7 1.7-7.2 2.8-1.5 1-2.4 3.3-2.8 7.2-.4-3.9-1.3-6.2-2.8-7.2C7.7 13.7 5.4 13 2 12c3.4-1 5.7-1.7 7.2-2.8C10.7 8.2 11.6 5.9 12 2Z"
                />
            </svg>
            <span className="thinking-text">{label ? `${label}…` : 'A pensar…'}</span>
        </div>
    );
}

/** The reasoning as it arrives: a few muted lines that follow the newest. */
function LiveReasoning({ text }: { text: string }) {
    const box = useRef<HTMLDivElement>(null);
    const [overflows, setOverflows] = useState(false);

    useEffect(() => {
        const element = box.current;
        if (element) {
            element.scrollTo({ top: element.scrollHeight });
            setOverflows(element.scrollHeight > element.clientHeight);
        }
    }, [text]);

    return (
        <div
            ref={box}
            className={cn(
                'ml-[11px] max-h-28 max-w-xl overflow-hidden border-l pl-3 text-[13px] leading-relaxed text-muted-foreground [&>div]:text-[13px] [&>div>*:first-child]:mt-0 [&>div>*:last-child]:mb-0',
                overflows && 'thinking-live',
            )}
        >
            <Markdown>{text}</Markdown>
        </div>
    );
}

function thoughtFor(ms: number) {
    const seconds = Math.max(1, Math.round(ms / 1000));

    return seconds < 60 ? `${seconds} s` : `${Math.floor(seconds / 60)} min ${seconds % 60} s`;
}

/** «Pensou durante 12 s», opened to read what the model reasoned. */
function ThoughtToggle({ thought }: { thought: Thought }) {
    const [open, setOpen] = useState(false);

    return (
        <div className="flex max-w-xl flex-col items-start">
            <button
                type="button"
                onClick={() => setOpen(!open)}
                aria-expanded={open}
                className="inline-flex items-center gap-1 px-1 py-0.5 text-xs text-muted-foreground transition-colors hover:text-foreground"
            >
                {thought.ms > 0 ? `Pensou durante ${thoughtFor(thought.ms)}` : 'Raciocínio'}
                <ChevronRight className={cn('size-3.5 transition-transform', open && 'rotate-90')} />
            </button>
            {open && (
                <div className="mt-1 mb-1 ml-[11px] border-l pl-3 text-[13px] leading-relaxed text-muted-foreground [&>div]:text-[13px] [&>div>*:first-child]:mt-0 [&>div>*:last-child]:mb-0">
                    <Markdown>{thought.text}</Markdown>
                </div>
            )}
        </div>
    );
}

function EventLine({ message, onOpenRun }: { message: ChatMessage; onOpenRun: OpenRun }) {
    return (
        <div className="my-3 flex items-center gap-3 text-xs text-muted-foreground">
            <span className="h-px flex-1 bg-border" />
            <span className="max-w-[80%] text-center">
                {message.body}
                <span className="ml-1.5 opacity-80">
                    · <Time value={message.created_at} />
                </span>
            </span>
            <span className="h-px flex-1 bg-border" />
        </div>
    );
}

function ReportCard({ message, onOpenRun }: { message: ChatMessage; onOpenRun: OpenRun }) {
    return (
        <details className="group mt-4 ml-11.5 rounded-xl border border-dashed px-3 py-2 text-xs text-muted-foreground">
            <summary className="flex cursor-pointer list-none items-center gap-2 [&::-webkit-details-marker]:hidden">
                <FileText className="size-3.5 shrink-0" />
                <span className="min-w-0 flex-1 truncate">
                    Relatório de <span className="font-medium text-foreground/80">{message.author}</span>
                    <span className="ml-1.5 group-open:hidden">— {message.body.slice(0, 120)}</span>
                </span>
                <Time value={message.created_at} className="shrink-0" />
                <ChevronDown className="size-3.5 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="mt-2 border-t pt-2 text-sm text-foreground">
                <Markdown>{message.body}</Markdown>
            </div>
        </details>
    );
}

const handoffStatus: Record<Handoff['status'], string> = {
    working: 'a trabalhar',
    answered: 'respondeu',
    failed: 'não terminou',
};

/**
 * One agent messaging another, as an inline row like Grok's "Messaged …" /
 * "Message from …": who, when and, for a request still with the colleague,
 * that it is working on it. Open, it shows what was written; the other
 * conversation opens from the row when the viewer may see it.
 */
function HandoffRow({ message, handoff }: { message: ChatMessage; handoff: Handoff }) {
    const agent = handoff.agent ?? 'Agente';
    const out = handoff.direction === 'out';
    const Icon = out ? ArrowUpRight : ArrowDownLeft;

    return (
        <details className="group my-3 flex flex-col items-center">
            <summary className="mx-auto flex max-w-full cursor-pointer list-none flex-wrap items-center justify-center gap-x-1.5 gap-y-1 rounded-full border bg-background px-3 py-1 text-xs text-muted-foreground shadow-xs transition-colors hover:bg-accent/50 [&::-webkit-details-marker]:hidden">
                <Icon className="size-3.5 shrink-0" />
                <span>{out ? 'Mensagem enviada a' : 'Mensagem de'}</span>
                <AgentAvatar name={agent} className="size-4 rounded text-[8px]" />
                <span className="font-medium text-foreground/85">{agent}</span>
                <span className="opacity-70">·</span>
                <Time value={message.created_at} />
                {out && handoff.role === 'request' && (
                    <>
                        <span className="opacity-70">·</span>
                        <span
                            className={cn(
                                'inline-flex items-center gap-1',
                                handoff.status === 'working' && 'text-status-running',
                                handoff.status === 'failed' && 'text-status-danger',
                            )}
                        >
                            {handoff.status === 'working' ? (
                                <Loader2 className="size-3 animate-spin" />
                            ) : handoff.status === 'answered' ? (
                                <Check className="size-3 text-status-success" />
                            ) : (
                                <X className="size-3" />
                            )}
                            {handoffStatus[handoff.status]}
                        </span>
                    </>
                )}
                <ChevronDown className="size-3 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="mx-auto mt-2 w-full max-w-xl rounded-xl border border-dashed bg-card/60 px-4 py-3 text-sm">
                <div className="mb-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                    {handoff.role === 'request' ? 'Pedido' : 'Resposta'}
                </div>
                <div className="[&>div>*:first-child]:mt-0 [&>div>*:last-child]:mb-0">
                    <Markdown>{message.body}</Markdown>
                </div>
                {handoff.other_task_id && (
                    <Link
                        href={`/tasks/${handoff.other_task_id}`}
                        className="mt-2 inline-block text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    >
                        Abrir a conversa com {agent}
                    </Link>
                )}
            </div>
        </details>
    );
}

/**
 * What the agent made in this turn (a draft, a report, a task, a reminder),
 * one button each, so what it says it did can be opened (docs/DECISOES.md,
 * "Ver o que o agente fez").
 */
function ResultLinks({ calls, shown = [] }: { calls: ToolCall[]; shown?: string[] }) {
    const links = calls
        .map((call) => call.link)
        .filter((link): link is { label: string; href: string } => !!link)
        // A draft already shown as an email card needs no «Ver rascunho».
        .filter((link) => !shown.includes(link.href))
        .filter((link, index, all) => all.findIndex((other) => other.href === link.href && other.label === link.label) === index);

    if (links.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {links.map((link) => (
                <Link
                    key={`${link.href}-${link.label}`}
                    href={link.href}
                    className="inline-flex h-7 items-center gap-1.5 rounded-full border bg-background px-3 text-xs font-medium text-foreground/85 shadow-xs transition-colors hover:bg-accent"
                >
                    <ExternalLink className="size-3 text-muted-foreground" />
                    {link.label}
                </Link>
            ))}
        </div>
    );
}
