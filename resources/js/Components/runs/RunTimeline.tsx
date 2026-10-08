import { AlertTriangle, Brain, CheckSquare, CornerDownRight, type LucideIcon, MessageSquare, Wrench } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Markdown } from '@/Components/Markdown';
import { StatusDot, type Tone } from '@/Components/Status';
import { approvalFacts } from '@/lib/approvals';
import { dateTime, duration, time, withoutTags } from '@/lib/format';
import { cn } from '@/lib/utils';

/*
 * The timeline of one run: used by the run page and by the side panel of a
 * conversation, where "Ver execução" opens it without leaving the chat.
 */

export interface Step {
    id: number;
    seq: number;
    type: 'message' | 'reasoning' | 'tool_call' | 'tool_result' | 'approval' | 'error';
    tool_name: string | null;
    payload: Record<string, unknown> | null;
    duration_ms: number | null;
    created_at: string;
}

export const stepMeta: Record<Step['type'], { icon: LucideIcon; label: string; tone: Tone }> = {
    message: { icon: MessageSquare, label: 'Resposta', tone: 'success' },
    reasoning: { icon: Brain, label: 'Raciocínio', tone: 'idle' },
    tool_call: { icon: Wrench, label: 'Chamada', tone: 'running' },
    tool_result: { icon: CornerDownRight, label: 'Resultado', tone: 'idle' },
    approval: { icon: CheckSquare, label: 'Aprovação', tone: 'warning' },
    error: { icon: AlertTriangle, label: 'Erro', tone: 'danger' },
};

const iconTone: Record<Tone, string> = {
    running: 'bg-status-running/12 text-status-running',
    success: 'bg-status-success/12 text-status-success',
    warning: 'bg-status-warning/15 text-status-warning',
    danger: 'bg-status-danger/12 text-status-danger',
    idle: 'bg-muted text-muted-foreground',
};

/** The steps of a run, in order; `focusStepId` scrolls to and marks one step (a tool clicked in the chat). */
export function RunTimeline({
    steps,
    finished,
    output,
    toolNames = {},
    focusStepId,
}: {
    steps: Step[];
    finished: boolean;
    output?: string | null;
    toolNames?: Record<string, string>;
    focusStepId?: number | null;
}) {
    if (steps.length === 0) {
        return (
            <div className="flex items-center gap-2 rounded-xl border border-dashed px-4 py-6 text-sm text-muted-foreground">
                {!finished && <StatusDot tone="running" />}
                {finished ? 'Sem passos registados.' : 'À espera do primeiro passo…'}
            </div>
        );
    }

    return (
        <ol className="flex flex-col">
            {steps.map((step, index) => (
                <StepItem
                    key={step.id}
                    step={step}
                    last={index === steps.length - 1 && finished}
                    toolName={step.tool_name ? toolNames[step.tool_name] : undefined}
                    final={!!output && step.type === 'message' && pickText(step) === output}
                    focused={focusStepId === step.id}
                />
            ))}
            {!finished && (
                <li className="flex items-center gap-3 pl-1.5 text-xs text-muted-foreground">
                    <StatusDot tone="running" />A trabalhar…
                </li>
            )}
        </ol>
    );
}

const emailFence = /<email_externo_nao_confiavel>([\s\S]*?)<\/email_externo_nao_confiavel>/;

/**
 * What the run was asked. An inbound email arrives inside the untrusted-data
 * fence; it is shown as the email it is instead of the fence tags.
 */
export function RunRequest({ input }: { input: string }) {
    const email = input.match(emailFence);
    const instructions = withoutTags(email ? input.replace(emailFence, '') : input).trim();

    return (
        <div className="divide-y rounded-xl border bg-card">
            {instructions && (
                <div className="px-5 py-3">
                    <Markdown>{instructions}</Markdown>
                </div>
            )}
            {email && (
                <div className="px-5 py-3">
                    <p className="text-xs font-medium text-muted-foreground">
                        Email recebido · conteúdo externo, o agente lê-o como dados e não como ordens
                    </p>
                    <div className="mt-2 max-h-96 overflow-auto text-sm leading-relaxed break-words whitespace-pre-wrap">{email[1].trim()}</div>
                </div>
            )}
        </div>
    );
}

/** Machine output (JSON, the untrusted email fence) stays behind "Ver dados"; a short sentence is shown. */
const readable = (text: string | null) => (text && !/^\s*[[{<]/.test(text) && !text.includes('<email_externo_nao_confiavel>') ? text : null);

const pickText = (step: Step) => {
    const value = step.payload?.content ?? step.payload?.text ?? step.payload?.message;

    return typeof value === 'string' ? value : null;
};

function StepItem({
    step,
    last,
    toolName,
    final = false,
    focused = false,
}: {
    step: Step;
    last: boolean;
    toolName?: string;
    final?: boolean;
    focused?: boolean;
}) {
    const [open, setOpen] = useState(focused);
    const ref = useRef<HTMLLIElement>(null);

    useEffect(() => {
        if (focused) {
            setOpen(true);
            ref.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, [focused]);
    const meta = stepMeta[step.type] ?? stepMeta.message;
    const Icon = meta.icon;
    const payload = step.payload ?? {};
    const pick = (...keys: string[]) => keys.map((key) => payload[key]).find((value): value is string => typeof value === 'string') ?? null;
    const tool = step.type === 'tool_call' || step.type === 'tool_result';
    const raw =
        step.type === 'approval'
            ? [pick('summary'), pick('reason'), pick('note')].filter(Boolean).join(' · ') || null
            : pick('content', 'text', 'message');
    const text = tool ? readable(raw) : raw;
    const facts =
        step.type === 'tool_call' && payload.arguments && typeof payload.arguments === 'object'
            ? approvalFacts(payload.arguments as Record<string, unknown>)
            : [];
    const detail = step.type === 'tool_result' && raw ? prettyJson(raw) : facts.length > 0 ? null : JSON.stringify(payload, null, 2);

    return (
        <li ref={ref} className={cn('relative flex gap-3 pb-5', focused && 'rounded-lg bg-accent/50 ring-1 ring-primary/25 ring-offset-4 ring-offset-background')}>
            {!last && <span className="absolute top-7 bottom-0 left-3 w-px -translate-x-1/2 bg-border" aria-hidden="true" />}
            <span
                className={cn('relative flex size-6 shrink-0 items-center justify-center rounded-full ring-4 ring-background', iconTone[meta.tone])}
            >
                <Icon className="size-3.5" />
            </span>
            <div className="min-w-0 flex-1 pt-0.5">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm">
                    <span className="font-medium">{meta.label}</span>
                    {step.tool_name && (
                        <span className="text-muted-foreground" title={step.tool_name}>
                            {toolName ?? step.tool_name}
                        </span>
                    )}
                    <span className="ml-auto flex items-baseline gap-2 text-[11px] text-muted-foreground tabular-nums">
                        {step.duration_ms !== null && <span>{duration(step.duration_ms)}</span>}
                        <span title={dateTime(step.created_at)}>{time(step.created_at)}</span>
                    </span>
                </div>
                {text && final ? (
                    <p className="mt-1 text-sm text-muted-foreground">A resposta completa está na conversa e em «Resposta final».</p>
                ) : (
                    text &&
                    (step.type === 'message' ? (
                        <div className="mt-1 text-sm">
                            <Markdown>{text}</Markdown>
                        </div>
                    ) : (
                        <p
                            className={cn(
                                'mt-1 text-sm break-words whitespace-pre-wrap',
                                tool ? 'line-clamp-2' : 'line-clamp-6',
                                step.type === 'error' ? 'text-status-danger' : 'text-muted-foreground',
                            )}
                        >
                            {text}
                        </p>
                    ))
                )}
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    className="mt-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                    aria-expanded={open}
                >
                    {open ? 'Esconder dados' : step.type === 'tool_call' ? 'Ver o que enviou' : tool ? 'Ver o que recebeu' : 'Ver dados'}
                </button>
                {open && (
                    <div className="mt-2 space-y-2">
                        {facts.length > 0 && (
                            <dl className="grid gap-x-4 gap-y-1 rounded-lg border bg-card p-3 text-sm sm:grid-cols-[minmax(0,10rem)_1fr]">
                                {facts.map((fact) => (
                                    <div key={fact.label} className="contents">
                                        <dt className="text-muted-foreground">{fact.label}</dt>
                                        <dd className="min-w-0 break-words">{fact.value}</dd>
                                    </div>
                                ))}
                            </dl>
                        )}
                        {detail && (
                            <pre className="max-h-72 overflow-auto rounded-lg border bg-muted/50 p-3 font-mono text-xs break-words whitespace-pre-wrap">
                                {detail}
                            </pre>
                        )}
                    </div>
                )}
            </div>
        </li>
    );
}

/** A tool answer that is JSON reads better indented. */
function prettyJson(text: string) {
    try {
        return JSON.stringify(JSON.parse(text), null, 2);
    } catch {
        return text;
    }
}
