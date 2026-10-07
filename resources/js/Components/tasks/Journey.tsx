import { Link, router } from '@inertiajs/react';
import { ArrowRight, CornerDownLeft, Mail } from 'lucide-react';
import { Fragment, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Monogram } from '@/Components/Blocks';
import { StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { usd } from '@/lib/format';
import { cn } from '@/lib/utils';

/** The path of one case across agents and people (App\Tasks\TaskJourney). */
export interface Journey {
    root: { id: number; ref: string; title: string; is_current: boolean };
    now: {
        type: 'agent' | 'person' | 'done';
        name: string | null;
        avatar_url: string | null;
        doing: string;
        since: string;
        approval_id: number | null;
        task: { id: number; ref: string } | null;
    };
    stops: {
        key: string;
        type: 'agent' | 'person' | 'email';
        name: string;
        avatar_url: string | null;
        seconds: number;
        at: string;
        via: string | null;
        via_kind: string | null;
    }[];
    hops: Hop[];
    totals: {
        seconds: number;
        agent_seconds: number;
        waiting_seconds: number;
        other_seconds: number;
        cost_usd: number;
        agents: number;
        people: number;
        handoffs: number;
        by_agents: number;
        workflow: string | null;
        open: boolean;
    };
}

interface Hop {
    at: string;
    type: 'email' | 'handoff' | 'work' | 'approval' | 'question' | 'returned';
    from: string | null;
    to: string | null;
    from_name: string | null;
    to_name: string | null;
    to_type: string | null;
    how: string;
    how_label: string;
    reason: string;
    quote: boolean;
    chips: string[];
    seconds: number;
    task: { id: number | null; ref: string } | null;
    status: { value: string; label: string; open: boolean } | null;
    approval_id: number | null;
    counting: boolean;
    is_current: boolean;
}

const clock = new Intl.DateTimeFormat('pt-PT', { hour: '2-digit', minute: '2-digit' });
const time = (value: string) => clock.format(new Date(value));

/** "45 s", "12 min", "1 h 40". */
export const span = (seconds: number) => {
    if (seconds < 60) {
        return `${Math.max(0, Math.round(seconds))} s`;
    }
    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)} min`;
    }
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, '0');

    return days > 0 ? `${days} d ${hours} h` : `${hours} h ${minutes}`;
};

const howTone: Record<string, string> = {
    agent: 'text-primary ring-primary/30 bg-primary/8',
    flow: 'text-status-running ring-status-running/30 bg-status-running/8',
    ceiling: 'text-warning-strong ring-status-warning/40 bg-status-warning/12',
    level: 'text-warning-strong ring-status-warning/40 bg-status-warning/12',
    question: 'text-warning-strong ring-status-warning/40 bg-status-warning/12',
};

function hopTone(hop: Hop): Tone {
    if (hop.counting) {
        return hop.type === 'approval' || hop.type === 'question' ? 'warning' : 'running';
    }
    if (hop.status?.open) {
        return 'idle';
    }
    if (hop.status && ['rejected', 'cancelled', 'expired', 'blocked'].includes(hop.status.value)) {
        return 'danger';
    }

    return 'success';
}

function Face({ type, name, url, className }: { type: string; name: string; url: string | null; className?: string }) {
    if (type === 'email') {
        return (
            <span className={cn('inline-flex size-9 items-center justify-center rounded-full bg-muted text-muted-foreground', className)}>
                <Mail className="size-4" />
            </span>
        );
    }

    return type === 'agent' ? (
        <AgentAvatar name={name} url={url} className={cn('size-9 rounded-full', className)} />
    ) : (
        <Monogram name={name} className={cn('size-9', className)} />
    );
}

/** Who had the case, in order, with how it got to each of them. */
function Lane({ journey }: { journey: Journey }) {
    const holder = journey.now.type === 'done' ? null : journey.now.name;
    const lastIndex = journey.stops.length - 1;
    const waitingFor = (Date.now() - new Date(journey.now.since).getTime()) / 1000;

    return (
        <div className="overflow-x-auto rounded-xl border bg-card px-3 pt-5 pb-3">
            <ol className="flex min-w-[36rem] items-start">
                {journey.stops.map((stop, index) => {
                    const current = holder !== null && stop.name === holder;
                    const waiting = current && journey.now.type === 'person';

                    return (
                        <li key={stop.key} className="relative flex min-w-0 flex-1 flex-col items-center gap-1 px-1 text-center">
                            {index > 0 && (
                                <span
                                    aria-hidden
                                    className="absolute top-[18px] right-[calc(50%+1.5rem)] left-[calc(-50%+1.5rem)] flex justify-center"
                                >
                                    <span
                                        className={cn(
                                            'h-0.5 w-full rounded-full',
                                            index === lastIndex && journey.totals.open
                                                ? 'bg-[repeating-linear-gradient(90deg,var(--status-idle)_0_5px,transparent_5px_9px)]'
                                                : 'bg-status-success/60',
                                        )}
                                    />
                                    {stop.via && (
                                        <span
                                            className="absolute -top-4 max-w-full truncate px-1 text-[10.5px] text-muted-foreground"
                                            title={stop.via}
                                        >
                                            {stop.via}
                                        </span>
                                    )}
                                </span>
                            )}
                            <span
                                className={cn(
                                    'relative rounded-full ring-2 ring-offset-2 ring-offset-card',
                                    waiting ? 'ring-status-warning' : current ? 'ring-status-running' : 'ring-status-success/60',
                                )}
                            >
                                <Face type={stop.type} name={stop.name} url={stop.avatar_url} />
                            </span>
                            <span className="mt-1 line-clamp-2 text-xs leading-tight font-medium">{stop.name}</span>
                            <span className={cn('text-[11px] tabular-nums', waiting ? 'text-warning-strong' : 'text-muted-foreground')}>
                                {stop.type === 'email' ? time(stop.at) : waiting ? `à espera há ${span(waitingFor)}` : span(stop.seconds)}
                            </span>
                        </li>
                    );
                })}
            </ol>
        </div>
    );
}

function HopRow({ hop, longest }: { hop: Hop; longest: number }) {
    const tone = hopTone(hop);
    const title = hop.type === 'work' ? `${hop.to_name ?? '—'} trabalha` : null;

    return (
        <li
            className={cn(
                'grid grid-cols-[3rem_0.75rem_minmax(0,1fr)] gap-x-3 py-3 sm:grid-cols-[3rem_0.75rem_minmax(0,1fr)_6rem]',
                hop.is_current && 'rounded-lg bg-accent/40',
            )}
        >
            <span className="pt-0.5 text-xs text-muted-foreground tabular-nums">{time(hop.at)}</span>
            <span className="pt-1.5">
                <StatusDot tone={tone} pulse={tone === 'running'} />
            </span>
            <div className="flex min-w-0 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm font-medium">
                    {title ?? (
                        <>
                            {hop.type === 'returned' && <CornerDownLeft className="size-3.5 text-muted-foreground" />}
                            <span>{hop.from_name ?? 'Alguém'}</span>
                            <ArrowRight className="size-3.5 text-muted-foreground" />
                            <span>{hop.to_name ?? '—'}</span>
                        </>
                    )}
                    {hop.type !== 'returned' && (
                        <span
                            className={cn(
                                'rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset',
                                howTone[hop.how] ?? 'bg-muted text-muted-foreground ring-border',
                            )}
                        >
                            {hop.how_label}
                        </span>
                    )}
                </div>
                <p className={cn('text-sm leading-relaxed text-foreground/85', hop.quote && 'text-muted-foreground italic')}>
                    {hop.quote ? `«${hop.reason}»` : hop.reason}
                </p>
                {(hop.chips.length > 0 || hop.task) && (
                    <div className="flex flex-wrap gap-1.5 pt-0.5">
                        {hop.task &&
                            (hop.task.id ? (
                                <Link
                                    href={`/tasks/${hop.task.id}`}
                                    className="rounded-full border bg-background px-2 py-0.5 font-mono text-[11px] text-muted-foreground hover:text-foreground"
                                >
                                    {hop.task.ref}
                                </Link>
                            ) : (
                                <span className="rounded-full border bg-background px-2 py-0.5 font-mono text-[11px] text-muted-foreground">
                                    {hop.task.ref}
                                </span>
                            ))}
                        {hop.status && hop.type !== 'returned' && (
                            <span className="rounded-full border bg-background px-2 py-0.5 text-[11px] text-muted-foreground">
                                {hop.status.label}
                            </span>
                        )}
                        {hop.chips.map((chip) => (
                            <span
                                key={chip}
                                className="max-w-full truncate rounded-full border bg-background px-2 py-0.5 text-[11px] text-muted-foreground"
                            >
                                {chip}
                            </span>
                        ))}
                    </div>
                )}
            </div>
            {hop.type !== 'returned' && (
                <div className="col-start-3 flex items-center gap-2 pt-1 sm:col-start-4 sm:flex-col sm:items-end sm:gap-1 sm:pt-0">
                    <span className={cn('text-xs tabular-nums', tone === 'warning' && 'font-medium text-warning-strong')}>
                        {span(hop.seconds)}
                        {hop.counting && <span className="ml-1 font-normal text-muted-foreground">e a contar</span>}
                    </span>
                    <span className="h-1 w-16 overflow-hidden rounded-full bg-muted sm:w-full">
                        <span
                            className={cn('block h-full rounded-full', tone === 'warning' ? 'bg-status-warning' : 'bg-status-running')}
                            style={{ width: `${Math.max(3, Math.min(100, (hop.seconds / Math.max(longest, 1)) * 100))}%` }}
                        />
                    </span>
                </div>
            )}
        </li>
    );
}

function decide(id: number, action: 'approve' | 'reject', done: () => void) {
    router.post(`/approvals/${id}/${action}`, {}, { preserveScroll: true, onFinish: done });
}

/**
 * The "Percurso" tab of a task: who has the case now, every hand-off with
 * why and how long, and where the time went (docs/DECISOES.md, "Percurso").
 */
export function JourneyView({ journey, taskId }: { journey: Journey; taskId: number }) {
    const [busy, setBusy] = useState(false);
    const { totals, now } = journey;
    const longest = Math.max(...journey.hops.map((hop) => hop.seconds), 1);
    const share = (seconds: number) => `${totals.seconds > 0 ? (seconds / totals.seconds) * 100 : 0}%`;

    return (
        <div className="flex flex-col gap-4">
            {!journey.root.is_current && (
                <p className="text-xs text-muted-foreground">
                    Este caso começou em{' '}
                    <Link href={`/tasks/${journey.root.id}`} className="font-mono text-foreground hover:underline">
                        {journey.root.ref}
                    </Link>{' '}
                    «{journey.root.title}». A tarefa aberta está destacada.
                </p>
            )}

            <div className="grid gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                <div className="flex flex-col gap-3 rounded-xl border bg-card p-4">
                    <h3 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                        {now.type === 'done' ? 'Caso fechado' : 'Agora com'}
                    </h3>
                    {now.type === 'done' ? (
                        <p className="text-sm">{now.doing}</p>
                    ) : (
                        <div className="flex items-center gap-3">
                            <span
                                className={cn(
                                    'rounded-full ring-2 ring-offset-2 ring-offset-card',
                                    now.type === 'person' ? 'ring-status-warning' : 'ring-status-running',
                                )}
                            >
                                <Face type={now.type} name={now.name ?? '?'} url={now.avatar_url} className="size-10" />
                            </span>
                            <div className="min-w-0">
                                <p className="font-semibold">{now.name}</p>
                                <p className="line-clamp-2 text-sm text-muted-foreground">{now.doing}</p>
                            </div>
                        </div>
                    )}
                    {now.approval_id !== null && now.type === 'person' && (
                        <div className="flex gap-2">
                            <Button
                                size="sm"
                                className="flex-1"
                                disabled={busy}
                                onClick={() => (setBusy(true), decide(now.approval_id!, 'approve', () => setBusy(false)))}
                            >
                                Aprovar
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                className="flex-1"
                                disabled={busy}
                                onClick={() => (setBusy(true), decide(now.approval_id!, 'reject', () => setBusy(false)))}
                            >
                                Rejeitar
                            </Button>
                            <Button size="sm" variant="ghost" asChild>
                                <Link href="/approvals">Abrir</Link>
                            </Button>
                        </div>
                    )}
                    {now.task && now.task.id !== taskId && (
                        <Link href={`/tasks/${now.task.id}`} className="w-fit text-xs text-muted-foreground hover:text-foreground">
                            Abrir <span className="font-mono">{now.task.ref}</span>
                        </Link>
                    )}
                </div>

                <div className="flex flex-col gap-2 rounded-xl border bg-card p-4">
                    <h3 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">
                        Tempo {totals.open ? 'até agora' : 'total'} · {span(totals.seconds)}
                    </h3>
                    <div className="flex h-2 overflow-hidden rounded-full bg-muted">
                        <span className="h-full bg-status-running" style={{ width: share(totals.agent_seconds) }} />
                        <span className="h-full bg-status-running/40" style={{ width: share(totals.other_seconds) }} />
                        <span className="h-full bg-status-warning" style={{ width: share(totals.waiting_seconds) }} />
                    </div>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-sm">
                        {[
                            ['bg-status-running', 'Agentes a trabalhar', totals.agent_seconds],
                            ['bg-status-running/40', 'Passagens e filas', totals.other_seconds],
                            ['bg-status-warning', 'À espera de pessoas', totals.waiting_seconds],
                        ].map(([color, label, seconds]) => (
                            <Fragment key={label as string}>
                                <dt className="flex items-center gap-2 text-muted-foreground">
                                    <span className={cn('size-2 rounded-full', color as string)} />
                                    {label}
                                </dt>
                                <dd className="text-right tabular-nums">{span(seconds as number)}</dd>
                            </Fragment>
                        ))}
                    </dl>
                    <p className="border-t pt-2 text-xs text-muted-foreground">
                        {totals.agents} {totals.agents === 1 ? 'agente' : 'agentes'}, {totals.people} {totals.people === 1 ? 'pessoa' : 'pessoas'} ·{' '}
                        {totals.handoffs} {totals.handoffs === 1 ? 'passagem' : 'passagens'}
                        {totals.by_agents > 0 && `, ${totals.by_agents} ${totals.by_agents === 1 ? 'decidida' : 'decididas'} pelos agentes`}
                        {totals.workflow && ` · fluxo «${totals.workflow}»`} · {usd(totals.cost_usd)}
                    </p>
                </div>
            </div>

            <Lane journey={journey} />

            <ol className="flex flex-col divide-y divide-dashed rounded-xl border bg-card px-3">
                {journey.hops.map((hop, index) => (
                    <HopRow key={index} hop={hop} longest={longest} />
                ))}
            </ol>

            <p className="text-xs leading-relaxed text-muted-foreground">
                Cada passagem é uma sub-tarefa: quem passa o trabalho continua dono da sua e recebe o resultado. Um agente passa trabalho a quem está
                abaixo dele ou à sua chefia no organigrama; num fluxo, a passagem é a desenhada. Preços a clientes e o que passa o tecto sobem sempre
                a uma pessoa.
            </p>
        </div>
    );
}
