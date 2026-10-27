import { Link, router, usePage } from '@inertiajs/react';
import { Activity, ChevronDown, ExternalLink, Wrench } from 'lucide-react';

import { Markdown } from '@/Components/Markdown';
import { StatusBadge, StatusDot, runTone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { useLive } from '@/hooks/useLive';
import { useToolNames } from '@/hooks/useToolNames';
import { ago, dateTime, plainText, runTitle } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { RunSummary, SharedProps } from '@/types';

/** A live run with its conversation and the step it is on (App\\Ai\\Runs\\AgentNow). */
export type NowRun = RunSummary & {
    started_at: string | null;
    task: { id: number | null; ref: string | null; title: string | null; kind: 'task' | 'chat'; visible: boolean } | null;
    step: { type: string; tool_name: string | null; at: string } | null;
};

/** Where a run is followed: its conversation with the run open in the side panel, or the run on its own. */
export const runHref = (run: NowRun) => (run.task?.id ? `/tasks/${run.task.id}?execucao=${run.id}` : `/settings/runs/${run.id}`);

/** The step a run is on, in words. */
export function stepLabel(run: NowRun, toolNames: Record<string, string> | undefined) {
    if (run.status === 'queued') {
        return 'Na fila';
    }

    switch (run.step?.type) {
        case 'tool_call':
            return `A usar ${(run.step.tool_name && toolNames?.[run.step.tool_name]) ?? run.step.tool_name ?? 'uma ferramenta'}`;
        case 'message':
            return 'A escrever a resposta';
        case 'approval':
            return 'À espera de aprovação';
        default:
            return 'A pensar';
    }
}

/** What the run is about: the conversation or task, else the request. */
export function workTitle(run: NowRun, toolNames: Record<string, string> | undefined) {
    if (run.task && !run.task.visible) {
        return run.task.kind === 'chat' ? 'Numa conversa privada' : 'Numa tarefa a que não tem acesso';
    }

    if (run.task?.title) {
        return run.task.title;
    }

    return run.title ?? runTitle(run.input, toolNames);
}

/**
 * What the agent is doing right now, at the top of its page: each queued or
 * running piece of work with its conversation and current step, live, and a
 * link that opens the run inside the conversation. Idle, one line with its
 * last activity.
 */
export function NowPanel({ active, last }: { active: NowRun[]; last: NowRun | null }) {
    const toolNames = useToolNames();

    if (active.length === 0) {
        return (
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground">
                <StatusDot tone="idle" />
                <span className="text-foreground">Sem trabalho em curso.</span>
                {last ? (
                    <span className="min-w-0 truncate">
                        Última actividade {ago(last.finished_at ?? last.created_at)}:{' '}
                        {last.task && !last.task.visible ? (
                            workTitle(last, toolNames)
                        ) : (
                            <Link href={runHref(last)} className="text-foreground underline-offset-4 hover:underline">
                                {workTitle(last, toolNames)}
                            </Link>
                        )}
                    </span>
                ) : (
                    <span>Ainda não trabalhou.</span>
                )}
            </div>
        );
    }

    return (
        <section
            aria-label="A fazer agora"
            className="flex flex-col gap-2 rounded-xl border border-status-running/30 bg-[color-mix(in_oklch,var(--status-running)_6%,var(--card))] p-4"
        >
            <h2 className="flex items-center gap-2 text-xs font-medium tracking-wide text-status-running uppercase">
                <StatusDot tone="running" />A fazer agora
            </h2>
            <ul className="flex flex-col divide-y divide-status-running/15">
                {active.map((run) => (
                    <NowRow key={run.id} run={run} toolNames={toolNames} />
                ))}
            </ul>
        </section>
    );
}

export function NowRow({ run, toolNames, reload = ['now'] }: { run: NowRun; toolNames: Record<string, string> | undefined; reload?: string[] }) {
    const { tenant } = usePage<SharedProps>().props;
    const hidden = run.task !== null && !run.task.visible;

    // Each step the run takes refreshes the line, so the current step follows the run live.
    useLive(tenant && !hidden ? `tenant.${tenant.id}.run.${run.id}` : null, ['AgentRunStepAdded'], () => router.reload({ only: reload }));

    return (
        <li className="flex flex-col gap-2 py-2 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:gap-4">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{workTitle(run, toolNames)}</p>
                <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1 text-foreground/80">
                        {run.step?.type === 'tool_call' && <Wrench className="size-3" />}
                        {stepLabel(run, toolNames)}
                    </span>
                    <span aria-hidden>·</span>
                    <span title={dateTime(run.started_at ?? run.created_at)}>
                        {run.status === 'queued' ? 'pedido' : 'começou'} {ago(run.started_at ?? run.created_at)}
                    </span>
                    {run.requested_by && (
                        <>
                            <span aria-hidden>·</span>
                            <span>a pedido de {run.requested_by}</span>
                        </>
                    )}
                </p>
            </div>
            {!hidden && (
                <Button variant="outline" size="sm" asChild className="self-start sm:self-auto">
                    <Link href={runHref(run)}>
                        <ExternalLink />
                        {run.task ? (run.task.kind === 'chat' ? 'Abrir conversa' : 'Abrir tarefa') : 'Ver execução'}
                    </Link>
                </Button>
            )}
        </li>
    );
}

/** A run that ended elsewhere: what it was, how it ended and what it answered, with the link to it. */
function DoneRow({ run, toolNames }: { run: NowRun; toolNames: Record<string, string> | undefined }) {
    const hidden = run.task !== null && !run.task.visible;
    const result = run.status === 'failed' ? run.error : plainText(run.output);

    return (
        <li className="flex flex-col gap-2 py-2 first:pt-0 last:pb-0 sm:flex-row sm:items-start sm:gap-4">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{workTitle(run, toolNames)}</p>
                <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <StatusBadge tone={runTone(run.status)}>{run.status_label}</StatusBadge>
                    <span title={dateTime(run.finished_at)}>terminou {ago(run.finished_at)}</span>
                    {run.requested_by && (
                        <>
                            <span aria-hidden>·</span>
                            <span>a pedido de {run.requested_by}</span>
                        </>
                    )}
                </p>
                {!hidden && result && <p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{result}</p>}
            </div>
            {!hidden && (
                <Button variant="outline" size="sm" asChild className="self-start">
                    <Link href={runHref(run)}>
                        <ExternalLink />
                        {run.task ? (run.task.kind === 'chat' ? 'Abrir conversa' : 'Abrir tarefa') : 'Ver execução'}
                    </Link>
                </Button>
            )}
        </li>
    );
}

/**
 * In a conversation, what the agent is busy with somewhere else (a task, a
 * routine, a flow), so "a trabalhar" in the sidebar is explained where the
 * person is looking, with a link to follow it (docs/DECISOES.md, "Agente
 * ocupado noutro sítio"). What it finished there in the last half hour stays,
 * with how it ended and its answer, so the card does not just vanish when the
 * work ends (docs/DECISOES.md, "Trabalho noutro sítio que terminou").
 */
export function ElsewherePanel({ agent, active, done = [], reload }: { agent: string; active: NowRun[]; done?: NowRun[]; reload: string[] }) {
    const toolNames = useToolNames();

    if (active.length === 0 && done.length === 0) {
        return null;
    }

    return (
        <div className="my-3 flex flex-col gap-3">
            {active.length > 0 && (
                <section
                    aria-label="A fazer agora"
                    className="flex flex-col gap-2 rounded-xl border border-status-running/30 bg-[color-mix(in_oklch,var(--status-running)_6%,var(--card))] px-4 py-3"
                >
                    <h2 className="flex items-center gap-2 text-xs font-medium tracking-wide text-status-running uppercase">
                        <StatusDot tone="running" />A fazer agora, fora desta conversa
                    </h2>
                    <p className="text-xs text-muted-foreground">
                        {agent} está ocupado {active.length === 1 ? 'com outro trabalho' : `com ${active.length} trabalhos`}. Pode escrever-lhe na
                        mesma.
                    </p>
                    <ul className="flex flex-col divide-y divide-status-running/15">
                        {active.map((run) => (
                            <NowRow key={run.id} run={run} toolNames={toolNames} reload={reload} />
                        ))}
                    </ul>
                </section>
            )}
            {done.length > 0 && (
                <section aria-label="Terminou há pouco" className="flex flex-col gap-2 rounded-xl border bg-card px-4 py-3">
                    <h2 className="text-xs font-medium tracking-wide text-muted-foreground uppercase">Terminou há pouco, fora desta conversa</h2>
                    <ul className="flex flex-col divide-y">
                        {done.map((run) => (
                            <DoneRow key={run.id} run={run} toolNames={toolNames} />
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}

/**
 * The agent's work elsewhere, in its conversation (docs/DECISOES.md, "Agente
 * contínuo"): a line in the thread where it began, «Comecei» while it runs
 * (with the step it is on, live) and «Terminei» when it ends, opening to its
 * answer. The steps open in the side panel, never on another page.
 */
export function BackgroundRow({ run, onOpenRun }: { run: NowRun; onOpenRun: (runId: number) => void }) {
    const { tenant } = usePage<SharedProps>().props;
    const toolNames = useToolNames();
    const hidden = run.task !== null && !run.task.visible;
    const live = run.status === 'queued' || run.status === 'running';
    const result = run.status === 'failed' ? run.error : run.output;

    useLive(tenant && live && !hidden ? `tenant.${tenant.id}.run.${run.id}` : null, ['AgentRunStepAdded'], () =>
        router.reload({ only: ['agent_background', 'agent_elsewhere'] }),
    );

    const verb = live
        ? run.status === 'queued'
            ? 'Vou tratar de'
            : 'Comecei'
        : run.status === 'completed'
          ? 'Terminei'
          : run.status === 'failed'
            ? 'Não terminei'
            : 'Parei';

    return (
        <details className="group my-3 flex flex-col items-center">
            <summary
                className={cn(
                    'mx-auto flex max-w-full cursor-pointer list-none flex-wrap items-center justify-center gap-x-1.5 gap-y-1 rounded-full border px-3 py-1 text-xs text-muted-foreground shadow-xs transition-colors hover:bg-accent/50 [&::-webkit-details-marker]:hidden',
                    live ? 'border-status-running/40 bg-[color-mix(in_oklch,var(--status-running)_6%,var(--background))]' : 'bg-background',
                )}
            >
                {live ? <StatusDot tone="running" /> : <StatusDot tone={runTone(run.status)} pulse={false} />}
                <span className="font-medium text-foreground/85">{verb}:</span>
                <span className="max-w-[22rem] truncate text-foreground/85">{workTitle(run, toolNames)}</span>
                <span className="opacity-70">·</span>
                {live ? (
                    <span className="inline-flex items-center gap-1 text-status-running">
                        {run.step?.type === 'tool_call' && <Wrench className="size-3" />}
                        {stepLabel(run, toolNames)}
                    </span>
                ) : (
                    <span title={dateTime(run.finished_at)}>{ago(run.finished_at ?? run.created_at)}</span>
                )}
                <ChevronDown className="size-3 shrink-0 transition-transform group-open:rotate-180" />
            </summary>
            <div className="mx-auto mt-2 w-full max-w-xl rounded-xl border border-dashed bg-card/60 px-4 py-3 text-sm">
                <p className="mb-2 text-xs text-muted-foreground">
                    {run.trigger_label}
                    {run.requested_by && ` · a pedido de ${run.requested_by}`} ·{' '}
                    <span title={dateTime(run.started_at ?? run.created_at)}>
                        {run.status === 'queued' ? 'pedido' : 'começou'} {ago(run.started_at ?? run.created_at)}
                    </span>
                </p>
                {hidden ? (
                    <p className="text-xs text-muted-foreground">Foi num sítio a que não tem acesso.</p>
                ) : (
                    <>
                        {!live && result && (
                            <div className="max-h-48 overflow-hidden text-[13px] [mask-image:linear-gradient(to_bottom,black_75%,transparent)]">
                                <Markdown>{result}</Markdown>
                            </div>
                        )}
                        <div className="mt-2 flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" className="h-7 text-xs" onClick={() => onOpenRun(run.id)}>
                                <Activity />
                                {live ? 'Acompanhar ao vivo' : 'Ver passo a passo'}
                            </Button>
                            {run.task?.id && (
                                <Button variant="ghost" size="sm" className="h-7 text-xs" asChild>
                                    <Link href={`/tasks/${run.task.id}`}>
                                        <ExternalLink />
                                        {run.task.kind === 'chat' ? 'Abrir a conversa' : 'Abrir a tarefa'}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </>
                )}
            </div>
        </details>
    );
}
