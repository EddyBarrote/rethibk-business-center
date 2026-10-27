import { AlertTriangle, Brain, CheckSquare, CornerDownRight, type LucideIcon, MessageSquare, Wrench } from 'lucide-react';
import { type ReactNode, type Ref, useEffect, useRef, useState } from 'react';

import { Markdown } from '@/Components/Markdown';
import { StatusDot, type Tone } from '@/Components/Status';
import { approvalFacts } from '@/lib/approvals';
import { dateTime, duration, plainText, time, withoutTags } from '@/lib/format';
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

/**
 * A tool call and what came back, shown as one line; every other step is a line of its own.
 * The result pairs with the last open call to the same tool (TaskController::toolCalls pairs the same way).
 */
type Entry = { key: number; call: Step | null; result: Step | null; step: Step };

function entriesOf(steps: Step[]): Entry[] {
    const entries: Entry[] = [];
    const open = new Map<string, Entry>();

    for (const step of steps) {
        const name = step.tool_name;

        if (step.type === 'tool_call' && name) {
            const entry = { key: step.id, call: step, result: null, step };
            entries.push(entry);
            open.set(name, entry);
        } else if ((step.type === 'tool_result' || step.type === 'error') && name && open.has(name)) {
            open.get(name)!.result = step;
            open.delete(name);
        } else if (step.type === 'tool_result') {
            entries.push({ key: step.id, call: null, result: step, step });
        } else {
            entries.push({ key: step.id, call: null, result: null, step });
        }
    }

    return entries;
}

/**
 * The steps of a run, one compact line each; a click opens the details. `focusStepId`
 * scrolls to and opens one step (a tool clicked in the chat, by its call or its result).
 */
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

    const entries = entriesOf(steps);

    return (
        <ol className="flex flex-col">
            {entries.map((entry, index) => {
                const focused = focusStepId !== null && focusStepId !== undefined && [entry.step.id, entry.result?.id].includes(focusStepId);
                const last = index === entries.length - 1 && finished;

                return entry.call || entry.result ? (
                    <ToolItem key={entry.key} entry={entry} last={last} finished={finished} toolNames={toolNames} focused={focused} />
                ) : (
                    <StepItem
                        key={entry.key}
                        step={entry.step}
                        last={last}
                        final={!!output && entry.step.type === 'message' && pickText(entry.step) === output}
                        focused={focused}
                    />
                );
            })}
            {!finished && (
                <li className="flex items-center gap-2.5 px-1.5 py-1 text-xs text-muted-foreground">
                    <span className="flex size-5 items-center justify-center">
                        <StatusDot tone="running" />
                    </span>
                    A trabalhar…
                </li>
            )}
        </ol>
    );
}

const emailFence = /<email_externo_nao_confiavel>([\s\S]*?)<\/email_externo_nao_confiavel>/;

/**
 * What the run was asked. An inbound email arrives inside the untrusted-data
 * fence; it is shown as the email it is instead of the fence tags. Plain,
 * without the card, where it opens inside a panel.
 */
export function RunRequest({ input, plain = false }: { input: string; plain?: boolean }) {
    const email = input.match(emailFence);
    const instructions = withoutTags(email ? input.replace(emailFence, '') : input).trim();

    return (
        <div className={plain ? 'flex flex-col gap-3' : 'divide-y rounded-xl border bg-card'}>
            {instructions && (
                <div className={plain ? undefined : 'px-5 py-3'}>
                    <Markdown>{instructions}</Markdown>
                </div>
            )}
            {email && (
                <div className={plain ? undefined : 'px-5 py-3'}>
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

/** Opens with a click, and by itself when the chat points at it. */
function useOpen(focused: boolean) {
    const [open, setOpen] = useState(focused);
    const ref = useRef<HTMLLIElement>(null);

    useEffect(() => {
        if (focused) {
            setOpen(true);
            ref.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }, [focused]);

    return { open, toggle: () => setOpen((value) => !value), ref };
}

/** One line of the timeline: icon, what happened, duration and time; the details open below it. */
function Row({
    rowRef,
    tone,
    icon: Icon,
    last,
    focused,
    open,
    onToggle,
    duration: ms,
    at,
    children,
    details,
}: {
    rowRef: Ref<HTMLLIElement>;
    tone: Tone;
    icon: LucideIcon;
    last: boolean;
    focused: boolean;
    open: boolean;
    onToggle: (() => void) | null;
    duration?: number | null;
    at: string;
    children: ReactNode;
    details?: ReactNode;
}) {
    const line = (
        <>
            <span className={cn('relative flex size-5 shrink-0 items-center justify-center rounded-full', iconTone[tone])}>
                <Icon className="size-3" />
            </span>
            <span className="min-w-0 flex-1">{children}</span>
            <span className="flex shrink-0 items-baseline gap-2 self-start pt-px text-[11px] leading-5 text-muted-foreground tabular-nums">
                {ms !== null && ms !== undefined && <span>{duration(ms)}</span>}
                <span title={dateTime(at)}>{time(at)}</span>
            </span>
        </>
    );

    return (
        <li ref={rowRef} className={cn('relative', focused && 'rounded-md bg-accent/50 ring-1 ring-primary/25')}>
            {!last && <span className="absolute top-6 -bottom-1 left-4 w-px -translate-x-1/2 bg-border" aria-hidden="true" />}
            {onToggle ? (
                <button
                    type="button"
                    onClick={onToggle}
                    aria-expanded={open}
                    className="flex w-full items-start gap-2.5 rounded-md px-1.5 py-1 text-left text-sm hover:bg-muted/60"
                >
                    {line}
                </button>
            ) : (
                <div className="flex items-start gap-2.5 px-1.5 py-1 text-sm">{line}</div>
            )}
            {open && details && <div className="pt-1 pr-1.5 pb-3 pl-9">{details}</div>}
        </li>
    );
}

/** A tool call and its result in one line: the tool's name and, greyed, the gist of the answer. */
function ToolItem({
    entry,
    last,
    finished,
    toolNames,
    focused,
}: {
    entry: Entry;
    last: boolean;
    finished: boolean;
    toolNames: Record<string, string>;
    focused: boolean;
}) {
    const { open, toggle, ref } = useOpen(focused);
    const { call, result } = entry;
    const key = (call ?? result)?.tool_name ?? '';
    const failed = result?.type === 'error';
    const waiting = !result && !finished;
    const answer = result ? pickText(result) : null;
    const gist = readable(answer);
    const args = call?.payload?.arguments && typeof call.payload.arguments === 'object' ? (call.payload.arguments as Record<string, unknown>) : null;
    const facts = args ? approvalFacts(args) : [];
    const sent = facts.length > 0 ? null : args && Object.keys(args).length > 0 ? JSON.stringify(args, null, 2) : null;
    const received = answer ? prettyJson(answer) : null;

    return (
        <Row
            rowRef={ref}
            tone={failed ? 'danger' : waiting ? 'running' : 'idle'}
            icon={failed ? AlertTriangle : Wrench}
            last={last}
            focused={focused}
            open={open}
            onToggle={toggle}
            duration={result?.duration_ms}
            at={(call ?? entry.step).created_at}
            details={
                <div className="@container">
                    <div className="grid gap-2 @lg:grid-cols-2">
                        <Detail label="Enviou">
                            {facts.length > 0 ? (
                                <dl className="grid gap-x-3 gap-y-1 text-xs @xs:grid-cols-[minmax(0,7rem)_1fr]">
                                    {facts.map((fact) => (
                                        <div key={fact.label} className="contents">
                                            <dt className="text-muted-foreground">{fact.label}</dt>
                                            <dd className="min-w-0 break-words">{fact.value}</dd>
                                        </div>
                                    ))}
                                </dl>
                            ) : sent ? (
                                <pre className="font-mono text-xs break-words whitespace-pre-wrap">{sent}</pre>
                            ) : (
                                <p className="text-xs text-muted-foreground">Nada.</p>
                            )}
                        </Detail>
                        <Detail label="Recebeu">
                            {received ? (
                                <pre className={cn('font-mono text-xs break-words whitespace-pre-wrap', failed && 'text-status-danger')}>
                                    {received}
                                </pre>
                            ) : (
                                <p className="text-xs text-muted-foreground">{waiting ? 'À espera da resposta…' : 'Sem resposta.'}</p>
                            )}
                        </Detail>
                    </div>
                </div>
            }
        >
            <span className="flex min-w-0 items-baseline gap-2 leading-5">
                {/* A long name shortens before it can run into the time; the gist gives way first. */}
                <span className="min-w-0 truncate" title={toolNames[key] ?? key}>
                    {toolNames[key] ?? key}
                </span>
                {gist && !failed && <span className="min-w-0 shrink-[999] truncate text-xs text-muted-foreground">{plainText(gist)}</span>}
            </span>
            {failed && answer && <span className="mt-0.5 block text-xs break-words whitespace-pre-wrap text-status-danger">{answer}</span>}
        </Row>
    );
}

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 rounded-lg bg-muted/40 p-2.5">
            <p className="mb-1 text-[11px] font-medium text-muted-foreground">{label}</p>
            <div className="max-h-72 overflow-auto">{children}</div>
        </div>
    );
}

/** A reply, a thought or an approval: a line or two that opens in full; an error is always shown in full. */
function StepItem({ step, last, final = false, focused = false }: { step: Step; last: boolean; final?: boolean; focused?: boolean }) {
    const { open, toggle, ref } = useOpen(focused);
    const meta = stepMeta[step.type] ?? stepMeta.message;
    const payload = step.payload ?? {};
    const pick = (...keys: string[]) => keys.map((key) => payload[key]).find((value): value is string => typeof value === 'string') ?? null;
    const text =
        step.type === 'approval'
            ? [pick('summary'), pick('reason'), pick('note')].filter(Boolean).join(' · ') || null
            : pick('content', 'text', 'message');
    const error = step.type === 'error';
    const preview = text ? plainText(text) : '';
    // Only text that does not fit in its preview opens.
    const opens = !error && !!text && (preview.length > 60 || text.includes('\n'));
    const label = step.type === 'message' ? (final ? 'Resposta final' : null) : meta.label;

    return (
        <Row
            rowRef={ref}
            tone={meta.tone}
            icon={meta.icon}
            last={last}
            focused={focused}
            open={open}
            onToggle={opens ? toggle : null}
            duration={step.duration_ms}
            at={step.created_at}
            details={
                text &&
                (step.type === 'message' ? (
                    <div className="text-sm">
                        <Markdown>{text}</Markdown>
                    </div>
                ) : (
                    <p className="text-sm break-words whitespace-pre-wrap text-muted-foreground">{text}</p>
                ))
            }
        >
            {error ? (
                <span className="block leading-5 break-words whitespace-pre-wrap text-status-danger">{text ?? meta.label}</span>
            ) : open ? (
                <span className="block leading-5 font-medium">{label ?? meta.label}</span>
            ) : (
                <span className={cn('block leading-5', step.type === 'message' ? 'line-clamp-2' : 'truncate')}>
                    {label && <span className="mr-2">{label}</span>}
                    <span className="text-muted-foreground">{preview || (label ? '' : meta.label)}</span>
                </span>
            )}
        </Row>
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
