import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bot,
    CalendarClock,
    CalendarPlus,
    CalendarSync,
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    ExternalLink,
    Link2,
    Plus,
    Repeat,
    Trash2,
    Undo2,
    User,
    X,
} from 'lucide-react';
import { type ComponentProps, type FormEvent, type ReactNode, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

type Kind = 'follow_up' | 'task' | 'routine' | 'google';

interface AgendaEvent {
    key: string;
    kind: Kind;
    title: string;
    note: string | null;
    at: string;
    all_day: boolean;
    done: boolean;
    overdue: boolean;
    who: { kind: 'user' | 'agent'; id: number; name: string } | null;
    by: string | null;
    context: string | null;
    link: string | null;
    /** A Google Calendar event: its page on Google. */
    url?: string | null;
    follow_up_id: number | null;
    can_edit: boolean;
    can_delete: boolean;
}

interface Props {
    events: AgendaEvent[];
    view: 'mes' | 'semana';
    date: string;
    from: string;
    to: string;
    today: string;
    everyone: boolean;
    can_see_everyone: boolean;
    timezone: string;
    has_feed: boolean;
    feed_url: string | null;
    /** Null when the platform has no Google client. */
    google: GoogleCalendar | null;
    google_result: 'ligado' | 'cancelado' | 'erro' | 'sem-permissoes' | null;
    filters: Filters;
    people: { key: string; kind: 'user' | 'agent'; name: string; count: number }[];
}

interface GoogleCalendar {
    connected: boolean;
    email: string | null;
    synced_at: string | null;
    error: string | null;
}

interface Filters {
    tipo: 'seguimentos' | 'prazos' | 'rotinas' | 'google' | null;
    pessoa: string | null;
    estado: 'por-fazer' | 'em-atraso' | 'feitos' | null;
}

/** The «tipo» filter, as the address writes it. */
const kindFilter: Record<Kind, NonNullable<Filters['tipo']>> = { follow_up: 'seguimentos', task: 'prazos', routine: 'rotinas', google: 'google' };

const kinds: Record<Kind, { label: string; chip: string; dot: string }> = {
    follow_up: { label: 'Seguimento', chip: 'bg-status-warning/15 text-foreground hover:bg-status-warning/25', dot: 'bg-status-warning' },
    task: { label: 'Prazo de tarefa', chip: 'bg-primary/10 text-foreground hover:bg-primary/20', dot: 'bg-primary' },
    routine: { label: 'Rotina', chip: 'bg-muted text-muted-foreground hover:bg-muted/70', dot: 'bg-muted-foreground/60' },
    google: { label: 'Google Calendar', chip: 'bg-status-success/12 text-foreground hover:bg-status-success/20', dot: 'bg-status-success' },
};

const googleResults: Record<NonNullable<Props['google_result']>, [ok: boolean, message: string]> = {
    ligado: [true, 'Google Calendar ligado. Os eventos aparecem dentro de momentos.'],
    cancelado: [false, 'A ligação ao Google Calendar foi cancelada.'],
    erro: [false, 'Não foi possível ligar o Google Calendar. Tente de novo.'],
    'sem-permissoes': [false, 'Faltou dar ao Google as duas permissões do calendário. Tente de novo e deixe-as marcadas.'],
};

const weekdayNames = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

/** Days are counted as plain dates (YYYY-MM-DD) at noon UTC, so no time zone moves them. */
const toDate = (day: string) => new Date(`${day}T12:00:00Z`);
const toKey = (d: Date) => d.toISOString().slice(0, 10);
const addDays = (day: string, n: number) => {
    const d = toDate(day);
    d.setUTCDate(d.getUTCDate() + n);

    return toKey(d);
};
const addMonths = (day: string, n: number) => {
    const d = toDate(day.slice(0, 8) + '01');
    d.setUTCMonth(d.getUTCMonth() + n);

    return toKey(d);
};

export default function AgendaIndex(props: Props) {
    const { events, view, date, from, to, today, everyone, can_see_everyone, timezone, filters, people } = props;
    const { flash } = usePage<SharedProps>().props;
    const [selected, setSelected] = useState<AgendaEvent | null>(null);
    const [creating, setCreating] = useState<string | null>(null);
    const [feedOpen, setFeedOpen] = useState(props.feed_url !== null);
    const [googleOpen, setGoogleOpen] = useState(false);

    // Back from Google: say how it went once, and take the word out of the address.
    useEffect(() => {
        if (props.google_result === null) {
            return;
        }
        const [ok, message] = googleResults[props.google_result];
        (ok ? toast.success : toast.error)(message);
        const url = new URL(window.location.href);
        url.searchParams.delete('google');
        window.history.replaceState(window.history.state, '', url);
    }, [props.google_result]);

    const dayOf = useMemo(() => {
        const format = new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' });

        return (iso: string) => format.format(new Date(iso));
    }, [timezone]);
    const hourOf = useMemo(() => {
        const format = new Intl.DateTimeFormat('pt-PT', { timeZone: timezone, hour: '2-digit', minute: '2-digit' });

        return (event: AgendaEvent) => (event.all_day ? null : format.format(new Date(event.at)));
    }, [timezone]);

    const byDay = useMemo(() => {
        const map = new Map<string, AgendaEvent[]>();
        events.forEach((event) => map.set(dayOf(event.at), [...(map.get(dayOf(event.at)) ?? []), event]));

        return map;
    }, [events, dayOf]);

    const days: string[] = [];
    for (let day = from; day <= to; day = addDays(day, 1)) {
        days.push(day);
    }

    const go = (next: Partial<{ vista: string; data: string; quem: string } & Filters>) => {
        const merged = { ...filters, ...next };

        router.get(
            '/agenda',
            {
                vista: next.vista ?? view,
                data: next.data ?? date,
                ...((next.quem ?? (everyone ? 'todos' : 'meu')) === 'todos' ? { quem: 'todos' } : {}),
                ...(merged.tipo ? { tipo: merged.tipo } : {}),
                ...(merged.pessoa ? { pessoa: merged.pessoa } : {}),
                ...(merged.estado ? { estado: merged.estado } : {}),
            },
            { preserveState: true, preserveScroll: true },
        );
    };
    const filtered = filters.tipo !== null || filters.pessoa !== null || filters.estado !== null;
    // The person chosen stays in the list even in a period where they have nothing.
    const peopleOptions =
        filters.pessoa && !people.some((p) => p.key === filters.pessoa)
            ? [
                  ...people,
                  { key: filters.pessoa, kind: filters.pessoa.startsWith('agent') ? 'agent' : 'user', name: 'Sem nada neste período', count: 0 },
              ]
            : people;

    const step = (direction: 1 | -1) => go({ data: view === 'mes' ? addMonths(date, direction) : addDays(date, 7 * direction) });

    const heading =
        view === 'mes'
            ? new Intl.DateTimeFormat('pt-PT', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(toDate(date))
            : `${new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(toDate(from))} – ${new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(toDate(to))}`;

    const openCount = events.filter((e) => e.kind !== 'routine' && e.kind !== 'google' && !e.done).length;
    const overdueCount = events.filter((e) => e.overdue).length;

    return (
        <AppLayout>
            <Head title="Agenda" />
            <PageHeader
                title="Agenda"
                description="Seguimentos, prazos das tarefas e rotinas, das pessoas e dos agentes."
                actions={
                    <>
                        {props.google &&
                            (props.google.connected ? (
                                <Button variant="outline" size="sm" onClick={() => setGoogleOpen(true)}>
                                    <span className={cn('size-2 rounded-full', props.google.error ? 'bg-status-danger' : kinds.google.dot)} />
                                    Google Calendar
                                </Button>
                            ) : (
                                <Button variant="outline" size="sm" asChild>
                                    {/* A full page load: Google's consent page is not an Inertia visit. */}
                                    <a href="/agenda/google/connect">
                                        <CalendarSync />
                                        Ligar o Google Calendar
                                    </a>
                                </Button>
                            ))}
                        <Button variant="outline" size="sm" onClick={() => setFeedOpen(true)}>
                            <Link2 />
                            Ver no Google ou Outlook
                        </Button>
                        <Button size="sm" onClick={() => setCreating(today)}>
                            <Plus />
                            Novo lembrete
                        </Button>
                    </>
                }
            />

            <div className="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" onClick={() => go({ data: today })}>
                    Hoje
                </Button>
                <div className="flex">
                    <Button variant="ghost" size="icon" aria-label="Anterior" onClick={() => step(-1)}>
                        <ChevronLeft />
                    </Button>
                    <Button variant="ghost" size="icon" aria-label="Seguinte" onClick={() => step(1)}>
                        <ChevronRight />
                    </Button>
                </div>
                <h2 className="mr-auto text-base font-semibold first-letter:uppercase">{heading}</h2>
                <span className="text-xs text-muted-foreground">
                    {openCount} por fazer{overdueCount > 0 && <span className="text-status-danger"> · {overdueCount} em atraso</span>}
                </span>
                {can_see_everyone && (
                    <Tabs value={everyone ? 'todos' : 'meu'} onValueChange={(quem) => go({ quem })}>
                        <TabsList>
                            <TabsTrigger value="meu">O meu</TabsTrigger>
                            <TabsTrigger value="todos">Toda a empresa</TabsTrigger>
                        </TabsList>
                    </Tabs>
                )}
                <Tabs value={view} onValueChange={(vista) => go({ vista })}>
                    <TabsList>
                        <TabsTrigger value="mes">Mês</TabsTrigger>
                        <TabsTrigger value="semana">Semana</TabsTrigger>
                    </TabsList>
                </Tabs>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {(Object.keys(kinds) as Kind[])
                    .filter((kind) => kind !== 'google' || props.google?.connected)
                    .map((kind) => {
                        const on = filters.tipo === kindFilter[kind];

                        return (
                            <button
                                key={kind}
                                type="button"
                                aria-pressed={on}
                                onClick={() => go({ tipo: on ? null : kindFilter[kind] })}
                                className={cn(
                                    'inline-flex h-8 items-center gap-1.5 rounded-full border px-3 text-xs transition-colors',
                                    on ? 'border-foreground/30 bg-muted font-medium text-foreground' : 'text-muted-foreground hover:bg-muted/60',
                                    filters.tipo !== null && !on && 'opacity-60',
                                )}
                            >
                                <span className={cn('size-2 rounded-full', kinds[kind].dot)} />
                                {kinds[kind].label}
                            </button>
                        );
                    })}
                <NativeSelect
                    aria-label="Pessoa ou agente"
                    className="h-8 w-auto min-w-44 text-xs"
                    value={filters.pessoa ?? ''}
                    onChange={(e) => go({ pessoa: e.target.value || null })}
                >
                    <option value="">Todas as pessoas e agentes</option>
                    {peopleOptions.some((p) => p.kind === 'user') && (
                        <optgroup label="Pessoas">
                            {peopleOptions
                                .filter((p) => p.kind === 'user')
                                .map((p) => (
                                    <option key={p.key} value={p.key}>
                                        {p.name}
                                        {p.count > 0 ? ` (${p.count})` : ''}
                                    </option>
                                ))}
                        </optgroup>
                    )}
                    {peopleOptions.some((p) => p.kind === 'agent') && (
                        <optgroup label="Agentes">
                            {peopleOptions
                                .filter((p) => p.kind === 'agent')
                                .map((p) => (
                                    <option key={p.key} value={p.key}>
                                        {p.name}
                                        {p.count > 0 ? ` (${p.count})` : ''}
                                    </option>
                                ))}
                        </optgroup>
                    )}
                </NativeSelect>
                <NativeSelect
                    aria-label="Estado"
                    className="h-8 w-auto text-xs"
                    value={filters.estado ?? ''}
                    onChange={(e) => go({ estado: (e.target.value || null) as Filters['estado'] })}
                >
                    <option value="">Qualquer estado</option>
                    <option value="por-fazer">Por fazer</option>
                    <option value="em-atraso">Em atraso</option>
                    <option value="feitos">Feitos</option>
                </NativeSelect>
                {filtered && (
                    <Button variant="ghost" size="sm" onClick={() => go({ tipo: null, pessoa: null, estado: null })}>
                        <X />
                        Limpar filtros
                    </Button>
                )}
            </div>

            {view === 'mes' ? (
                <div className="overflow-hidden rounded-xl border">
                    <div className="hidden grid-cols-7 border-b bg-muted/40 text-xs font-medium text-muted-foreground sm:grid">
                        {weekdayNames.map((name) => (
                            <div key={name} className="px-2 py-1.5">
                                {name}
                            </div>
                        ))}
                    </div>
                    <div className="grid grid-cols-1 sm:grid-cols-7">
                        {days.map((day) => {
                            const items = byDay.get(day) ?? [];
                            const outside = day.slice(0, 7) !== date.slice(0, 7);
                            if (outside && items.length === 0) {
                                return <div key={day} className="hidden min-h-28 border-r border-b bg-muted/20 last:border-r-0 sm:block" />;
                            }

                            return (
                                <div
                                    key={day}
                                    className={cn(
                                        'group relative min-h-28 border-b p-1.5 sm:border-r sm:[&:nth-child(7n)]:border-r-0',
                                        (outside || [6, 0].includes(toDate(day).getUTCDay())) && 'bg-muted/20',
                                        items.length === 0 && 'hidden sm:block',
                                    )}
                                >
                                    <div className="mb-1 flex items-center justify-between">
                                        <button
                                            type="button"
                                            onClick={() => go({ vista: 'semana', data: day })}
                                            className={cn(
                                                'inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums hover:bg-muted',
                                                outside && 'text-muted-foreground',
                                                day === today && 'bg-primary font-semibold !text-primary-foreground hover:bg-primary/90',
                                            )}
                                            title="Ver a semana"
                                        >
                                            {Number(day.slice(8))}
                                        </button>
                                        <span className="text-xs text-muted-foreground sm:hidden">
                                            {new Intl.DateTimeFormat('pt-PT', {
                                                weekday: 'long',
                                                day: 'numeric',
                                                month: 'long',
                                                timeZone: 'UTC',
                                            }).format(toDate(day))}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => setCreating(day)}
                                            className="hidden size-6 items-center justify-center rounded text-muted-foreground group-hover:inline-flex hover:bg-muted"
                                            aria-label="Novo lembrete neste dia"
                                        >
                                            <Plus className="size-3.5" />
                                        </button>
                                    </div>
                                    <DayLines
                                        items={items}
                                        showRoutines={filters.tipo === 'rotinas'}
                                        hourOf={hourOf}
                                        onOpen={setSelected}
                                        label={new Intl.DateTimeFormat('pt-PT', {
                                            weekday: 'long',
                                            day: 'numeric',
                                            month: 'long',
                                            timeZone: 'UTC',
                                        }).format(toDate(day))}
                                    />
                                </div>
                            );
                        })}
                    </div>
                </div>
            ) : (
                <div className="grid gap-3 lg:grid-cols-7">
                    {days.map((day, index) => {
                        const items = byDay.get(day) ?? [];

                        return (
                            <div key={day} className={cn('flex min-h-32 flex-col rounded-xl border', day === today && 'border-primary/60')}>
                                <div className="flex items-center justify-between border-b px-3 py-2">
                                    <span className="text-xs text-muted-foreground">{weekdayNames[index]}</span>
                                    <span className={cn('text-sm font-semibold tabular-nums', day === today && 'text-primary')}>
                                        {Number(day.slice(8))}
                                    </span>
                                </div>
                                <div className="grid flex-1 content-start gap-1.5 p-2">
                                    {items.map((event) => (
                                        <Card key={event.key} event={event} hour={hourOf(event)} onClick={() => setSelected(event)} />
                                    ))}
                                    <button
                                        type="button"
                                        onClick={() => setCreating(day)}
                                        className="mt-auto inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-xs text-muted-foreground hover:bg-muted hover:text-foreground"
                                    >
                                        <Plus className="size-3.5" />
                                        Lembrete
                                    </button>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}

            {events.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    {filtered
                        ? 'Nada neste período com estes filtros.'
                        : 'Nada marcado neste período. Os seguimentos que os agentes agendam e os prazos das tarefas aparecem aqui.'}
                </p>
            )}

            <EventDialog event={selected} hour={selected ? hourOf(selected) : null} dayOf={dayOf} onClose={() => setSelected(null)} />
            <NewReminder day={creating} onClose={() => setCreating(null)} />
            {props.google?.connected && <GoogleDialog open={googleOpen} onOpenChange={setGoogleOpen} google={props.google} />}
            <FeedDialog open={feedOpen} onOpenChange={setFeedOpen} hasFeed={props.has_feed} url={props.feed_url} flash={flash?.success ?? null} />
        </AppLayout>
    );
}

/** The part of a title that repeats ("Seguimento de cobrança - Olankha" → "Seguimento de cobrança"). */
const baseTitle = (title: string) => title.split(/\s[-–—:|]\s/)[0].trim();

interface Line {
    key: string;
    kind: Kind;
    label: string;
    events: AgendaEvent[];
}

/**
 * A day of the month view, made to be read at a glance: what is late comes
 * first, items of the same kind and title fold into one line («Seguimento de
 * cobrança · 3»), and the routines that run every day become one quiet line
 * at the bottom (shown in full when the «Rotina» filter is on). A folded line
 * opens the list of its items.
 */
function DayLines({
    items,
    showRoutines,
    hourOf,
    onOpen,
    label,
}: {
    items: AgendaEvent[];
    showRoutines: boolean;
    hourOf: (event: AgendaEvent) => string | null;
    onOpen: (event: AgendaEvent) => void;
    label: string;
}) {
    const routines = showRoutines ? [] : items.filter((event) => event.kind === 'routine');
    const lines: Line[] = [];
    items
        .filter((event) => showRoutines || event.kind !== 'routine')
        .forEach((event) => {
            const key = `${event.kind}:${baseTitle(event.title).toLowerCase()}`;
            const line = lines.find((l) => l.key === key);
            if (line) {
                line.events.push(event);
            } else {
                lines.push({ key, kind: event.kind, label: baseTitle(event.title), events: [event] });
            }
        });
    // Late first, then by the hour of the first item.
    lines.sort((a, b) => Number(b.events.some((e) => e.overdue)) - Number(a.events.some((e) => e.overdue)));

    const shown = lines.slice(0, 3);
    const rest = lines.slice(3).flatMap((line) => line.events);

    return (
        <div className="grid gap-0.5">
            {shown.map((line) =>
                line.events.length === 1 ? (
                    <Chip key={line.key} event={line.events[0]} hour={hourOf(line.events[0])} onClick={() => onOpen(line.events[0])} />
                ) : (
                    <DayList key={line.key} title={`${line.label} · ${line.events.length}`} events={line.events} hourOf={hourOf} onOpen={onOpen}>
                        <LineButton kind={line.kind} overdue={line.events.filter((e) => e.overdue).length}>
                            <span className="line-clamp-2 min-w-0 flex-1">{line.label}</span>
                            <span className="shrink-0 rounded-full bg-background/70 px-1.5 text-[10px] font-medium tabular-nums">
                                {line.events.length}
                            </span>
                        </LineButton>
                    </DayList>
                ),
            )}
            {rest.length > 0 && (
                <DayList title={label} events={rest} hourOf={hourOf} onOpen={onOpen}>
                    <button type="button" className="px-1.5 text-left text-xs text-muted-foreground hover:text-foreground">
                        +{rest.length} mais
                    </button>
                </DayList>
            )}
            {routines.length > 0 && (
                <DayList title={`Rotinas · ${label}`} events={routines} hourOf={hourOf} onOpen={onOpen}>
                    <button
                        type="button"
                        className="mt-0.5 inline-flex items-center gap-1 px-1.5 text-left text-[11px] text-muted-foreground/80 hover:text-foreground"
                    >
                        <Repeat className="size-3 shrink-0" />
                        {routines.length === 1 ? '1 rotina' : `${routines.length} rotinas`}
                    </button>
                </DayList>
            )}
        </div>
    );
}

function LineButton({ kind, overdue, children, ...props }: { kind: Kind; overdue: number; children: ReactNode } & ComponentProps<'button'>) {
    return (
        <button
            type="button"
            {...props}
            className={cn('flex min-w-0 items-start gap-1.5 rounded px-1.5 py-0.5 text-left text-xs leading-snug', kinds[kind].chip)}
        >
            <span className={cn('mt-1.5 size-1.5 shrink-0 rounded-full', overdue > 0 ? 'bg-status-danger' : kinds[kind].dot)} />
            {children}
        </button>
    );
}

/** The items behind a folded line, in a small list; one click opens an item. */
function DayList({
    title,
    events,
    hourOf,
    onOpen,
    children,
}: {
    title: string;
    events: AgendaEvent[];
    hourOf: (event: AgendaEvent) => string | null;
    onOpen: (event: AgendaEvent) => void;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{children}</PopoverTrigger>
            <PopoverContent align="start" className="w-80 p-0">
                <p className="border-b px-3 py-2 text-xs font-medium first-letter:uppercase">{title}</p>
                <ul className="max-h-80 overflow-y-auto py-1">
                    {events.map((event) => (
                        <li key={event.key}>
                            <button
                                type="button"
                                onClick={() => {
                                    setOpen(false);
                                    onOpen(event);
                                }}
                                className={cn(
                                    'flex w-full items-start gap-2 px-3 py-1.5 text-left text-xs hover:bg-muted',
                                    event.done && 'opacity-60',
                                )}
                            >
                                <span
                                    className={cn('mt-1 size-1.5 shrink-0 rounded-full', event.overdue ? 'bg-status-danger' : kinds[event.kind].dot)}
                                />
                                <span className={cn('w-10 shrink-0 tabular-nums', event.overdue ? 'text-status-danger' : 'text-muted-foreground')}>
                                    {hourOf(event) ?? 'Dia'}
                                </span>
                                <span className="min-w-0 flex-1">
                                    <span className={cn('block', event.done && 'line-through')}>{event.title}</span>
                                    {event.who && <span className="block truncate text-muted-foreground">{event.who.name}</span>}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            </PopoverContent>
        </Popover>
    );
}

function Chip({ event, hour, onClick }: { event: AgendaEvent; hour: string | null; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={event.title}
            className={cn(
                'flex min-w-0 items-start gap-1.5 rounded px-1.5 py-0.5 text-left text-xs leading-snug',
                kinds[event.kind].chip,
                event.done && 'line-through opacity-60',
            )}
        >
            <span className={cn('mt-1.5 size-1.5 shrink-0 rounded-full', event.overdue ? 'bg-status-danger' : kinds[event.kind].dot)} />
            <span className="line-clamp-2 min-w-0 flex-1">
                {hour && <span className={cn('mr-1 tabular-nums', event.overdue ? 'text-status-danger' : 'text-muted-foreground')}>{hour}</span>}
                {event.title}
            </span>
        </button>
    );
}

function Card({ event, hour, onClick }: { event: AgendaEvent; hour: string | null; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'grid gap-1 rounded-lg border-l-2 px-2 py-1.5 text-left text-xs',
                kinds[event.kind].chip,
                event.kind === 'follow_up'
                    ? 'border-l-status-warning'
                    : event.kind === 'task'
                      ? 'border-l-primary'
                      : event.kind === 'google'
                        ? 'border-l-status-success'
                        : 'border-l-muted-foreground/40',
                event.done && 'opacity-60',
                event.overdue && 'ring-1 ring-status-danger/60',
            )}
        >
            <span className="flex items-center gap-1 text-muted-foreground">
                {hour ?? 'Dia todo'}
                {event.context && event.kind !== 'routine' && <span className="truncate">· {event.context}</span>}
            </span>
            <span className={cn('line-clamp-3 font-medium', event.done && 'line-through')}>{event.title}</span>
            {event.who && <Who who={event.who} />}
        </button>
    );
}

function Who({ who }: { who: NonNullable<AgendaEvent['who']> }) {
    const Icon = who.kind === 'agent' ? Bot : User;

    return (
        <span className="inline-flex min-w-0 items-center gap-1 text-muted-foreground">
            <Icon className="size-3 shrink-0" />
            <span className="truncate">{who.name}</span>
        </span>
    );
}

function EventDialog({
    event,
    hour,
    dayOf,
    onClose,
}: {
    event: AgendaEvent | null;
    hour: string | null;
    dayOf: (iso: string) => string;
    onClose: () => void;
}) {
    const [moving, setMoving] = useState(false);
    const [when, setWhen] = useState({ date: '', time: '' });
    const [confirmDelete, setConfirmDelete] = useState(false);

    if (event === null) {
        return null;
    }

    const day = dayOf(event.at);
    const dayLabel = new Intl.DateTimeFormat('pt-PT', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(
        toDate(day),
    );
    const patch = (data: { done?: boolean; date?: string; time?: string | null }) =>
        router.patch(`/agenda/follow-ups/${event.follow_up_id}`, data, {
            preserveScroll: true,
            onSuccess: () => {
                setMoving(false);
                onClose();
            },
        });

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) {
                    setMoving(false);
                    onClose();
                }
            }}
        >
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                        <span className={cn('size-2 rounded-full', kinds[event.kind].dot)} />
                        {kinds[event.kind].label}
                        {event.done && ' · feito'}
                        {event.overdue && <span className="text-status-danger"> · em atraso</span>}
                    </span>
                    <DialogTitle className="pr-6 leading-snug">{event.title}</DialogTitle>
                    <DialogDescription className="first-letter:uppercase">
                        {dayLabel}
                        {hour ? `, ${hour}` : ''}
                    </DialogDescription>
                </DialogHeader>

                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                    {event.who && (
                        <>
                            <dt className="text-muted-foreground">
                                {event.kind === 'follow_up' ? 'Para' : event.kind === 'google' ? 'Calendário de' : 'Quem trata'}
                            </dt>
                            <dd>
                                <Who who={event.who} />
                            </dd>
                        </>
                    )}
                    {event.by && event.who?.name !== event.by && (
                        <>
                            <dt className="text-muted-foreground">Agendado por</dt>
                            <dd>{event.by}</dd>
                        </>
                    )}
                    {event.context && (
                        <>
                            <dt className="text-muted-foreground">{event.kind === 'routine' ? 'Horário' : 'Tarefa'}</dt>
                            <dd>{event.context}</dd>
                        </>
                    )}
                </dl>

                {event.note && <p className="rounded-lg bg-muted/50 p-3 text-sm whitespace-pre-line">{event.note}</p>}

                {moving && (
                    <form
                        className="grid grid-cols-2 gap-3"
                        onSubmit={(e: FormEvent) => {
                            e.preventDefault();
                            patch({ date: when.date, time: when.time || null });
                        }}
                    >
                        <Field id="move-date" label="Novo dia">
                            <Input
                                id="move-date"
                                type="date"
                                required
                                value={when.date}
                                onChange={(e) => setWhen({ ...when, date: e.target.value })}
                            />
                        </Field>
                        <Field id="move-time" label="Hora (opcional)">
                            <Input id="move-time" type="time" value={when.time} onChange={(e) => setWhen({ ...when, time: e.target.value })} />
                        </Field>
                        <div className="col-span-2 flex justify-end gap-2">
                            <Button type="button" variant="ghost" size="sm" onClick={() => setMoving(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" size="sm" disabled={!when.date}>
                                Adiar
                            </Button>
                        </div>
                    </form>
                )}

                <DialogFooter className="gap-2 sm:justify-between">
                    <div className="flex gap-2">
                        {event.can_delete && (
                            <Button variant="ghost" size="sm" className="text-status-danger" onClick={() => setConfirmDelete(true)}>
                                <Trash2 />
                                Apagar
                            </Button>
                        )}
                    </div>
                    <div className="flex flex-wrap justify-end gap-2">
                        {event.url && (
                            <Button variant="outline" size="sm" asChild>
                                <a href={event.url} target="_blank" rel="noreferrer noopener">
                                    <ExternalLink />
                                    Abrir no Google
                                </a>
                            </Button>
                        )}
                        {event.link && (
                            <Button variant="outline" size="sm" asChild>
                                <Link href={event.link}>
                                    <ExternalLink />
                                    Abrir
                                </Link>
                            </Button>
                        )}
                        {event.can_edit && !moving && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setWhen({ date: addDays(day, 1), time: hour ?? '' });
                                    setMoving(true);
                                }}
                            >
                                <CalendarClock />
                                Adiar
                            </Button>
                        )}
                        {event.can_edit &&
                            (event.done ? (
                                <Button variant="outline" size="sm" onClick={() => patch({ done: false })}>
                                    <Undo2 />
                                    Por fazer
                                </Button>
                            ) : (
                                <Button size="sm" onClick={() => patch({ done: true })}>
                                    <Check />
                                    Feito
                                </Button>
                            ))}
                    </div>
                </DialogFooter>

                <ConfirmDialog
                    open={confirmDelete}
                    onOpenChange={setConfirmDelete}
                    title="Apagar este lembrete?"
                    description="Deixa de aparecer na Agenda e não chega nenhum aviso."
                    confirmLabel="Apagar"
                    destructive
                    onConfirm={() =>
                        router.delete(`/agenda/follow-ups/${event.follow_up_id}`, {
                            preserveScroll: true,
                            onSuccess: () => {
                                setConfirmDelete(false);
                                onClose();
                            },
                        })
                    }
                />
            </DialogContent>
        </Dialog>
    );
}

function NewReminder({ day, onClose }: { day: string | null; onClose: () => void }) {
    const [form, setForm] = useState({ title: '', date: '', time: '', note: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [lastDay, setLastDay] = useState<string | null>(null);

    // A new day opens the form on that day.
    if (day !== lastDay) {
        setLastDay(day);
        if (day !== null) {
            setForm({ title: '', date: day, time: '', note: '' });
            setErrors({});
        }
    }

    return (
        <FormDialog
            open={day !== null}
            onOpenChange={(open) => !open && onClose()}
            title="Novo lembrete"
            description="Só para si. No dia chega-lhe uma notificação."
            submitLabel="Criar lembrete"
            processing={saving}
            disabled={!form.title.trim() || !form.date}
            onSubmit={() =>
                router.post(
                    '/agenda/follow-ups',
                    { ...form, time: form.time || null },
                    {
                        preserveScroll: true,
                        onStart: () => setSaving(true),
                        onFinish: () => setSaving(false),
                        onError: setErrors,
                        onSuccess: onClose,
                    },
                )
            }
        >
            <Field id="title" label="O quê" error={errors.title}>
                <Input
                    id="title"
                    value={form.title}
                    maxLength={255}
                    placeholder="Ligar à TVCabo"
                    onChange={(e) => setForm({ ...form, title: e.target.value })}
                />
            </Field>
            <div className="grid grid-cols-2 gap-3">
                <Field id="date" label="Dia" error={errors.date}>
                    <Input id="date" type="date" value={form.date} onChange={(e) => setForm({ ...form, date: e.target.value })} />
                </Field>
                <Field id="time" label="Hora (opcional)" error={errors.time}>
                    <Input id="time" type="time" value={form.time} onChange={(e) => setForm({ ...form, time: e.target.value })} />
                </Field>
            </div>
            <Field id="note" label="Nota (opcional)" error={errors.note}>
                <Textarea id="note" rows={3} value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
            </Field>
        </FormDialog>
    );
}

function FeedDialog({
    open,
    onOpenChange,
    hasFeed,
    url,
    flash,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hasFeed: boolean;
    url: string | null;
    flash: string | null;
}) {
    const [copied, setCopied] = useState(false);
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Ver a Agenda no Google ou Outlook</DialogTitle>
                    <DialogDescription>
                        Um link privado que o seu calendário lê de hora a hora. Leva os seus seguimentos e os prazos das suas tarefas e dos agentes
                        que lhe reportam. Só leitura: nada é escrito na sua conta.
                    </DialogDescription>
                </DialogHeader>

                {url ? (
                    <div className="grid gap-3">
                        <div className="flex gap-2">
                            <Input readOnly value={url} onFocus={(e) => e.target.select()} className="font-mono text-xs" />
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    void navigator.clipboard.writeText(url);
                                    setCopied(true);
                                }}
                            >
                                {copied ? <Check /> : <Copy />}
                                {copied ? 'Copiado' : 'Copiar'}
                            </Button>
                        </div>
                        <p className="text-xs text-status-warning">
                            Guarde-o agora: por segurança, este link só aparece esta vez. Quem o tiver vê a sua agenda.
                        </p>
                        <ol className="grid list-decimal gap-1 pl-5 text-sm text-muted-foreground">
                            <li>
                                Google Calendar: <span className="text-foreground">Outros calendários › + › A partir de URL</span>, cole o link.
                            </li>
                            <li>
                                Outlook: <span className="text-foreground">Adicionar calendário › Subscrever a partir da Web</span>, cole o link.
                            </li>
                        </ol>
                    </div>
                ) : hasFeed ? (
                    <p className="text-sm">Já tem um link activo. Um link novo desliga o anterior; terá de o trocar no calendário.</p>
                ) : (
                    flash && <p className="text-sm text-muted-foreground">{flash}</p>
                )}

                <DialogFooter className="gap-2 sm:justify-between">
                    <div>
                        {hasFeed && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-status-danger"
                                disabled={busy}
                                onClick={() => router.delete('/agenda/feed', options)}
                            >
                                Desligar o link
                            </Button>
                        )}
                    </div>
                    <Button size="sm" disabled={busy} onClick={() => router.post('/agenda/feed', {}, options)}>
                        <CalendarPlus />
                        {hasFeed || url ? 'Gerar um link novo' : 'Gerar o link'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/** The person's Google Calendar: what is kept in step, when it last was, and the way out. */
function GoogleDialog({ open, onOpenChange, google }: { open: boolean; onOpenChange: (open: boolean) => void; google: GoogleCalendar }) {
    const [busy, setBusy] = useState(false);
    const options = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Google Calendar</DialogTitle>
                    <DialogDescription>
                        Ligado a <span className="font-medium text-foreground">{google.email ?? 'a sua conta Google'}</span>.
                        {google.synced_at && (
                            <> Sincronizado {new Date(google.synced_at).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })}.</>
                        )}
                    </DialogDescription>
                </DialogHeader>

                <ul className="grid gap-2 text-sm">
                    <li className="flex gap-2">
                        <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', kinds.google.dot)} />
                        <span>
                            Os eventos do seu calendário principal aparecem aqui na Agenda.{' '}
                            <span className="text-muted-foreground">Só para si: nem os admins nem os agentes os vêem.</span>
                        </span>
                    </li>
                    <li className="flex gap-2">
                        <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', kinds.follow_up.dot)} />
                        <span>
                            Os seus seguimentos e prazos vão para um calendário à parte que a plataforma criou na sua conta.{' '}
                            <span className="text-muted-foreground">O seu calendário principal nunca é alterado.</span>
                        </span>
                    </li>
                    <li className="text-xs text-muted-foreground">Sincroniza a cada 5 minutos e quando abre a Agenda.</li>
                </ul>

                {google.error && <p className="rounded-lg bg-status-danger/10 p-3 text-sm text-status-danger">{google.error}</p>}

                <DialogFooter className="gap-2 sm:justify-between">
                    <Button
                        variant="ghost"
                        size="sm"
                        className="text-status-danger"
                        disabled={busy}
                        onClick={() => router.delete('/agenda/google', { ...options, onSuccess: () => onOpenChange(false) })}
                    >
                        Desligar
                    </Button>
                    <div className="flex gap-2">
                        {google.error && (
                            <Button variant="outline" size="sm" asChild>
                                <a href="/agenda/google/connect">Voltar a ligar</a>
                            </Button>
                        )}
                        <Button size="sm" disabled={busy} onClick={() => router.post('/agenda/google/sync', {}, options)}>
                            <CalendarSync />
                            Sincronizar agora
                        </Button>
                    </div>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
