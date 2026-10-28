import { Link, router, usePage } from '@inertiajs/react';
import { ChevronDown, ExternalLink, Wrench } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
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
            ? 'Vai tratar de'
            : 'A tratar de'
        : run.status === 'completed'
          ? 'Tratou de'
          : run.status === 'failed'
            ? 'Não terminou'
            : 'Parou';

    // Part of the agent's own thread (its face, then its body at the chat's width), with no card or border:
    // one quiet line, as Claude shows its steps, that opens to the answer it gave there.
    return (
        <div className="mt-4 flex gap-2.5">
            <AgentAvatar name={run.agent.name} className="mt-0.5 size-9 shrink-0 rounded-xl text-[11px]" />
            <details className="group min-w-0 flex-1">
                <summary className="flex min-h-9 cursor-pointer list-none items-center gap-2 text-sm text-muted-foreground transition-colors hover:text-foreground [&::-webkit-details-marker]:hidden">
                    <StatusDot tone={live ? 'running' : runTone(run.status)} pulse={live} />
                    <span className="min-w-0 truncate">
                        {verb} <span className="font-medium text-foreground">{workTitle(run, toolNames)}</span>
                    </span>
                    <span className="hidden shrink-0 text-xs sm:inline">
                        {live ? (
                            <span className="text-status-running">
                                {run.step?.type === 'tool_call' && <Wrench className="mr-1 inline size-3" />}
                                {stepLabel(run, toolNames).toLowerCase()}
                            </span>
                        ) : (
                            <span title={dateTime(run.finished_at)}>
                                {run.trigger_label.toLowerCase()} · {ago(run.finished_at ?? run.created_at)}
                            </span>
                        )}
                    </span>
                    <ChevronDown className="size-3.5 shrink-0 transition-transform group-open:rotate-180" />
                </summary>
                <div className="pt-1">
                    {hidden ? (
                        <p className="text-xs text-muted-foreground">Foi num sítio a que não tem acesso.</p>
                    ) : (
                        <>
                            {!live && result && <Markdown className="w-full px-1 py-0.5">{result}</Markdown>}
                            <p className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 px-1 text-[11px] text-muted-foreground">
                                <span>Fora desta conversa{run.requested_by ? `, a pedido de ${run.requested_by}` : ''}</span>
                                <button
                                    type="button"
                                    onClick={() => onOpenRun(run.id)}
                                    className="underline-offset-2 hover:text-foreground hover:underline"
                                >
                                    {live ? 'Acompanhar ao vivo' : 'Ver o passo a passo'}
                                </button>
                                {run.task?.id && (
                                    <Link href={`/tasks/${run.task.id}`} className="underline-offset-2 hover:text-foreground hover:underline">
                                        {run.task.kind === 'chat' ? 'Abrir a conversa' : 'Abrir a tarefa'}
                                    </Link>
                                )}
                            </p>
                        </>
                    )}
                </div>
            </details>
        </div>
    );
}

/**
 * The home screen's view of the agents (docs/DECISOES.md, "Início: os agentes
 * a trabalhar"): their faces (a ring on whoever is working), what each is
 * doing now and what they finished in the last day. Each line opens the work
 * in the person's conversation with that agent, its steps beside it.
 */
export function HomeAgents({
    agents,
    active,
    done,
    part = 'all',
}: {
    agents: { id: number; name: string; avatar_url: string | null }[];
    active: NowRun[];
    done: NowRun[];
    /** On a wide screen the faces stay under the box and the activity goes to the right-hand panel. */
    part?: 'all' | 'faces' | 'activity';
}) {
    const { tenant, sidebar_agents: sidebar = [] } = usePage<SharedProps>().props;
    const toolNames = useToolNames();

    useLive(
        tenant ? `tenant.${tenant.id}.agents` : null,
        ['AgentRunStarted', 'AgentRunFinished'],
        () => router.reload({ only: ['agents_now', 'sidebar_agents'] }),
        {
            only: ['agents_now', 'sidebar_agents'],
            poll: active.length > 0,
        },
    );

    const chatOf = (agentId: number) => sidebar.find((agent) => agent.id === agentId)?.chat_id ?? null;
    // In the person's conversation with the agent, the run open beside it; else where the run belongs.
    const href = (run: NowRun) => {
        if (run.task?.id) {
            return `/tasks/${run.task.id}?execucao=${run.id}`;
        }
        const chat = chatOf(run.agent.id);

        return chat ? `/tasks/${chat}?execucao=${run.id}` : `/settings/runs/${run.id}`;
    };
    const working = new Set(active.map((run) => run.agent.id));
    // In the right-hand panel each run is a quiet card, like the lines of the day above it.
    const panel = part === 'activity';
    const row = panel
        ? 'flex items-start gap-2.5 rounded-lg bg-muted/50 px-3 py-2.5 transition-colors hover:bg-muted'
        : '-mx-2 flex items-start gap-2.5 rounded-lg px-2 py-1.5 transition-colors hover:bg-muted/60';
    const heading = panel ? 'text-[11px] font-medium tracking-wide text-muted-foreground uppercase' : 'text-xs font-medium text-muted-foreground';
    const empty = panel ? 'rounded-lg border border-dashed px-3 py-2.5 text-xs text-muted-foreground' : 'text-sm text-muted-foreground';

    return (
        <div className="flex w-full flex-col gap-6">
            {part !== 'activity' && (
                <div className="flex flex-wrap justify-center gap-x-5 gap-y-3">
                    {agents.map((agent) => {
                        const chat = chatOf(agent.id);

                        return (
                            <Link
                                key={agent.id}
                                href={chat ? `/tasks/${chat}` : `/agents/${agent.id}`}
                                className="group flex w-16 flex-col items-center gap-1.5 text-center"
                            >
                                <AgentAvatar
                                    name={agent.name}
                                    url={agent.avatar_url}
                                    className="size-11 rounded-2xl text-xs transition-transform group-hover:scale-105"
                                />
                                <span
                                    className={cn(
                                        'line-clamp-2 text-[11px] leading-tight',
                                        working.has(agent.id) ? 'text-status-running' : 'text-muted-foreground',
                                    )}
                                >
                                    {agent.name.replace(/^Agente de /, '')}
                                </span>
                            </Link>
                        );
                    })}
                </div>
            )}

            {part !== 'faces' && (active.length > 0 || done.length > 0 || part === 'activity') && (
                <div className={cn('grid gap-6 text-left', part === 'all' && 'sm:grid-cols-2')}>
                    <section aria-label="A trabalhar agora" className={cn('flex min-w-0 flex-col', panel ? 'gap-1.5' : 'gap-1')}>
                        <h2 className={cn('mb-1 flex items-center gap-2', heading)}>
                            <StatusDot tone={active.length > 0 ? 'running' : 'idle'} />A trabalhar agora
                        </h2>
                        {active.length === 0 ? (
                            <p className={empty}>Nenhum agente está a trabalhar.</p>
                        ) : (
                            active.map((run) => (
                                <Link key={run.id} href={href(run)} className={cn('group', row)}>
                                    <AgentAvatar name={run.agent.name} className="mt-0.5 size-7 shrink-0 rounded-lg text-[10px]" />
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 text-sm">
                                            <span className="font-medium">{run.agent.name}</span> está a tratar de {workTitle(run, toolNames)}
                                        </span>
                                        <span className="block truncate text-xs text-status-running">
                                            {stepLabel(run, toolNames)} · {run.status === 'queued' ? 'pedido' : 'começou'}{' '}
                                            {ago(run.started_at ?? run.created_at)}
                                        </span>
                                    </span>
                                </Link>
                            ))
                        )}
                    </section>
                    <section aria-label="Concluído nas últimas 24 horas" className={cn('flex min-w-0 flex-col', panel ? 'gap-1.5' : 'gap-1')}>
                        <h2 className={cn('mb-1', heading)}>Concluído nas últimas 24 horas</h2>
                        {done.length === 0 ? (
                            <p className={empty}>Ainda nada hoje.</p>
                        ) : (
                            done.map((run) => (
                                <Link key={run.id} href={href(run)} className={cn('group', row)}>
                                    <AgentAvatar name={run.agent.name} className="mt-0.5 size-7 shrink-0 rounded-lg text-[10px]" />
                                    <span className="min-w-0 flex-1">
                                        <span className="line-clamp-2 text-sm">
                                            <span className="font-medium">{run.agent.name}</span>{' '}
                                            {run.status === 'failed' ? 'não terminou' : 'tratou de'} {workTitle(run, toolNames)}
                                        </span>
                                        <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                            <StatusDot tone={runTone(run.status)} pulse={false} className="size-1.5 [&>span]:size-1.5" />
                                            <span title={dateTime(run.finished_at)}>{ago(run.finished_at ?? run.created_at)}</span>
                                            <span aria-hidden>·</span>
                                            <span className="underline-offset-2 group-hover:underline">Ver o resultado</span>
                                        </span>
                                    </span>
                                </Link>
                            ))
                        )}
                    </section>
                </div>
            )}
        </div>
    );
}
