import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bot,
    CalendarClock,
    CalendarPlus,
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    ExternalLink,
    Link2,
    Plus,
    Trash2,
    Undo2,
    User,
    X,
} from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';

import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

type Kind = 'follow_up' | 'task' | 'routine';

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
    filters: Filters;
    people: { key: string; kind: 'user' | 'agent'; name: string; count: number }[];
}

interface Filters {
    tipo: 'seguimentos' | 'prazos' | 'rotinas' | null;
    pessoa: string | null;
    estado: 'por-fazer' | 'em-atraso' | 'feitos' | null;
}

/** The «tipo» filter, as the address writes it. */
const kindFilter: Record<Kind, NonNullable<Filters['tipo']>> = { follow_up: 'seguimentos', task: 'prazos', routine: 'rotinas' };

const kinds: Record<Kind, { label: string; chip: string; dot: string }> = {
    follow_up: { label: 'Seguimento', chip: 'bg-status-warning/15 text-foreground hover:bg-status-warning/25', dot: 'bg-status-warning' },
    task: { label: 'Prazo de tarefa', chip: 'bg-primary/10 text-foreground hover:bg-primary/20', dot: 'bg-primary' },
    routine: { label: 'Rotina', chip: 'bg-muted text-muted-foreground hover:bg-muted/70', dot: 'bg-muted-foreground/60' },
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

    const openCount = events.filter((e) => e.kind !== 'routine' && !e.done).length;
    const overdueCount = events.filter((e) => e.overdue).length;

    return (
        <AppLayout>
            <Head title="Agenda" />
            <PageHeader
                title="Agenda"
                description="Seguimentos, prazos das tarefas e rotinas, das pessoas e dos agentes."
                actions={
                    <>
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
                {(Object.keys(kinds) as Kind[]).map((kind) => {
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
                                return <div key={day} className="hidden min-h-24 border-r border-b bg-muted/20 last:border-r-0 sm:block" />;
                            }

                            return (
                                <div
                                    key={day}
                                    className={cn(
                                        'group relative min-h-24 border-b p-1.5 sm:border-r sm:[&:nth-child(7n)]:border-r-0',
                                        outside && 'bg-muted/20',
                                        items.length === 0 && 'hidden sm:block',
                                    )}
                                >
                                    <div className="mb-1 flex items-center justify-between">
                                        <button
                                            type="button"
                                            onClick={() => go({ vista: 'semana', data: day })}
                                            className={cn(
                                                'inline-flex size-6 items-center justify-center rounded-full text-xs tabular-nums hover:bg-muted',
                                                day === today && 'bg-primary font-semibold text-primary-foreground hover:bg-primary',
                                                outside && 'text-muted-foreground',
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
                                    <div className="grid gap-0.5">
                                        {items.slice(0, 3).map((event) => (
                                            <Chip key={event.key} event={event} hour={hourOf(event)} onClick={() => setSelected(event)} />
                                        ))}
                                        {items.length > 3 && (
                                            <button
                                                type="button"
                                                onClick={() => go({ vista: 'semana', data: day })}
                                                className="px-1.5 text-left text-xs text-muted-foreground hover:text-foreground"
                                            >
                                                +{items.length - 3} mais
                                            </button>
                                        )}
                                    </div>
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
            <FeedDialog open={feedOpen} onOpenChange={setFeedOpen} hasFeed={props.has_feed} url={props.feed_url} flash={flash?.success ?? null} />
        </AppLayout>
    );
}

function Chip({ event, hour, onClick }: { event: AgendaEvent; hour: string | null; onClick: () => void }) {
    return (
        <button
            type="button"
            onClick={onClick}
            title={event.title}
            className={cn(
                'flex min-w-0 items-center gap-1.5 rounded px-1.5 py-0.5 text-left text-xs',
                kinds[event.kind].chip,
                event.done && 'line-through opacity-60',
                event.overdue && 'ring-1 ring-status-danger/60',
            )}
        >
            <span className={cn('size-1.5 shrink-0 rounded-full', kinds[event.kind].dot)} />
            {hour && <span className="shrink-0 tabular-nums text-muted-foreground">{hour}</span>}
            <span className="truncate">{event.title}</span>
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
                event.kind === 'follow_up' ? 'border-l-status-warning' : event.kind === 'task' ? 'border-l-primary' : 'border-l-muted-foreground/40',
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
                            <dt className="text-muted-foreground">{event.kind === 'follow_up' ? 'Para' : 'Quem trata'}</dt>
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
