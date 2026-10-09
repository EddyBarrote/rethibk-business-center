import { Check, ChevronDown, FileText, Loader2, Lock, Wrench, X, Zap } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { MessageAttachments } from '@/Components/attachments/MessageAttachments';
import { Monogram } from '@/Components/Blocks';
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
    kind: 'message' | 'action' | 'event' | 'report';
    body: string;
    attachments: MessageAttachment[];
    run_id: number | null;
    created_at: string;
    /** The agent's questions on this message, answered on the card. */
    question?: AgentQuestions | null;
}

export interface ToolCall {
    step_id: number;
    key: string;
    name: string;
    status: 'running' | 'approval' | 'ok' | 'error' | 'rejected';
    duration_ms: number | null;
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
    viewer,
    onOpenRun,
}: {
    messages: ChatMessage[];
    toolCalls: Record<number, ToolCall[]>;
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
                    previous.author === message.author &&
                    previous.author_type === message.author_type &&
                    new Date(message.created_at).getTime() - new Date(previous.created_at).getTime() < 5 * 60_000;

                let tools: ToolCall[] = [];
                if (message.author_type === 'agent' && message.run_id && !toolsShown.has(message.run_id)) {
                    toolsShown.add(message.run_id);
                    tools = toolCalls[message.run_id] ?? [];
                }

                return (
                    <div key={message.id} className="flex flex-col">
                        {newDay && <DaySeparator label={dayLabel(message.created_at)} />}
                        {message.kind === 'event' ? (
                            <EventLine message={message} onOpenRun={onOpenRun} />
                        ) : message.kind === 'report' ? (
                            <ReportCard message={message} onOpenRun={onOpenRun} />
                        ) : isPerson(message) ? (
                            <PersonBubble message={message} continued={continued} own={viewer === message.author} />
                        ) : (
                            <AgentBubble message={message} continued={continued} tools={tools} onOpenRun={onOpenRun} />
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
                {message.attachments.length > 0 && <MessageAttachments attachments={message.attachments} className="justify-end" />}
                <Time value={message.created_at} className="px-1" />
            </div>
            {!continued ? <Monogram name={message.author} className="mt-0.5 size-7 shrink-0 rounded-full text-[10px]" /> : <span className="w-7 shrink-0" />}
        </div>
    );
}

function AgentBubble({
    message,
    continued,
    tools,
    onOpenRun,
}: {
    message: ChatMessage;
    continued: boolean;
    tools: ToolCall[];
    onOpenRun: OpenRun;
}) {
    return (
        <div className={cn('flex gap-2.5 pr-6 sm:pr-16', continued ? 'mt-1' : 'mt-4')}>
            {!continued ? <AgentAvatar name={message.author} className="mt-0.5 size-7 shrink-0 rounded-lg text-[10px]" /> : <span className="w-7 shrink-0" />}
            <div className="flex min-w-0 flex-1 flex-col items-start gap-1">
                {!continued && (
                    <div className="flex items-center gap-2 px-1">
                        <span className="text-xs font-medium text-foreground/80">{message.author}</span>
                        {message.kind === 'action' && <ActionBadge />}
                    </div>
                )}
                {message.body !== '' && (
                    <div className="max-w-full min-w-0 rounded-2xl rounded-tl-md border bg-card px-4 py-2.5 text-sm break-words shadow-xs [&>div>*:first-child]:mt-0 [&>div>*:last-child]:mb-0">
                        <Markdown>{message.body}</Markdown>
                    </div>
                )}
                {message.attachments.length > 0 && <MessageAttachments attachments={message.attachments} />}
                {message.question && <QuestionCard question={message.question} />}
                <div className="flex w-full flex-col items-start gap-1">
                    {tools.length > 0 && message.run_id && <ToolCalls runId={message.run_id} calls={tools} onOpenRun={onOpenRun} />}
                    <div className="flex items-center gap-2 px-1">
                        <Time value={message.created_at} />
                        {message.run_id && tools.length === 0 && <RunButton runId={message.run_id} onOpenRun={onOpenRun} />}
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
            className="font-mono text-[11px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
        >
            {children ?? `ver execução #${runId}`}
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

/** The agent working on this turn: what it is writing as it writes it, and the tools it has called so far. */
export function WorkingBubble({
    agent,
    runId,
    text,
    tool,
    calls,
    onOpenRun,
}: {
    agent: string;
    runId: number | null;
    text: string | null;
    tool: string | null;
    calls: ToolCall[];
    onOpenRun: OpenRun;
}) {
    return (
        <div className="mt-4 flex gap-2.5 pr-6 sm:pr-16">
            <span className="relative mt-0.5 h-fit shrink-0">
                <AgentAvatar name={agent} className="size-7 rounded-lg text-[10px]" />
                <StatusDot tone="running" className="absolute -right-0.5 -bottom-0.5" />
            </span>
            <div className="flex min-w-0 flex-1 flex-col items-start gap-1">
                <div className="flex items-center gap-2 px-1">
                    <span className="text-xs font-medium text-foreground/80">{agent}</span>
                    {tool && (
                        <span className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                            <Wrench className="size-3" />a usar <span className="font-mono">{tool}</span>
                        </span>
                    )}
                </div>
                <div className="max-w-full min-w-0 rounded-2xl rounded-tl-md border bg-card px-4 py-2.5 text-sm shadow-xs">
                    {text ? (
                        <div className="[&>*:first-child]:mt-0 [&>*:last-child]:after:ml-0.5 [&>*:last-child]:after:inline-block [&>*:last-child]:after:h-4 [&>*:last-child]:after:w-1.5 [&>*:last-child]:after:animate-pulse [&>*:last-child]:after:bg-primary [&>*:last-child]:after:align-middle [&>*:last-child]:after:content-['']">
                            <Markdown>{text}</Markdown>
                        </div>
                    ) : (
                        <span className="inline-flex items-center gap-1.5 text-muted-foreground">
                            <span className="flex gap-0.5">
                                <span className="size-1.5 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.3s]" />
                                <span className="size-1.5 animate-bounce rounded-full bg-muted-foreground/60 [animation-delay:-0.15s]" />
                                <span className="size-1.5 animate-bounce rounded-full bg-muted-foreground/60" />
                            </span>
                            A pensar…
                        </span>
                    )}
                </div>
                {runId && calls.length > 0 && <ToolCalls runId={runId} calls={calls} onOpenRun={onOpenRun} live />}
                {runId && (
                    <div className="px-1">
                        <RunButton runId={runId} onOpenRun={onOpenRun} />
                    </div>
                )}
            </div>
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
                {message.run_id && (
                    <span className="ml-1.5">
                        · <RunButton runId={message.run_id} onOpenRun={onOpenRun} />
                    </span>
                )}
            </span>
            <span className="h-px flex-1 bg-border" />
        </div>
    );
}

function ReportCard({ message, onOpenRun }: { message: ChatMessage; onOpenRun: OpenRun }) {
    return (
        <details className="group mt-4 mr-6 ml-9.5 rounded-xl border border-dashed px-3 py-2 text-xs text-muted-foreground sm:mr-16">
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
                {message.run_id && (
                    <div className="mt-2">
                        <RunButton runId={message.run_id} onOpenRun={onOpenRun} />
                    </div>
                )}
            </div>
        </details>
    );
}
