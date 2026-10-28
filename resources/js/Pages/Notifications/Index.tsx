import { Head, Link, router, usePage } from '@inertiajs/react';
import { Bell, BellOff, CheckCheck, CheckSquare, Mail, MailOpen, Search, SearchX, Settings2, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { ApprovalActions } from '@/Components/notifications/ApprovalActions';
import { dayKey, dayLabel, iconFor, levelTone, type Notice } from '@/Components/notifications/meta';
import { PageHeader } from '@/Components/PageHeader';
import { PagerArrows, Pagination } from '@/Components/Pagination';
import { StatusDot } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, number, plainText, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated, SharedProps } from '@/types';

interface Category {
    key: string;
    label: string;
    total: number;
    unread: number;
}

interface Props {
    notifications: Paginated<Notice>;
    categories: Category[];
    filters: { estado: 'todas' | 'por-ler'; tipo: string | null; q: string; por_pagina: number };
    per_page_options: number[];
    awaiting_decision: number;
}

const live = ['notifications', 'categories', 'awaiting_decision', 'auth'];

export default function NotificationsIndex({ notifications, categories, filters, awaiting_decision, per_page_options }: Props) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const [selected, setSelected] = useState<string[]>([]);
    const unreadTotal = categories.reduce((sum, c) => sum + c.unread, 0);
    const total = categories.reduce((sum, c) => sum + c.total, 0);
    const current = categories.find((c) => c.key === filters.tipo);

    useLive(
        tenant && auth.user ? `tenant.${tenant.id}.user.${auth.user.id}` : null,
        ['UserNotified', 'ApprovalRequested'],
        () => router.reload({ only: live }),
        { only: live, poll: true, intervalMs: 15000 },
    );

    // A new page of results is a new selection.
    useEffect(() => setSelected([]), [filters.estado, filters.tipo, filters.q, notifications.current_page]);

    // A changed filter starts again at page 1; the default page size stays out of the address.
    const go = (next: Partial<Props['filters']>) => {
        const merged = { ...filters, ...next };
        router.get(
            '/notifications',
            {
                ...(merged.estado === 'por-ler' ? { estado: 'por-ler' } : {}),
                ...(merged.tipo ? { tipo: merged.tipo } : {}),
                ...(merged.q ? { q: merged.q } : {}),
                ...(merged.por_pagina !== per_page_options[0] ? { por_pagina: merged.por_pagina } : {}),
            },
            { preserveState: true, preserveScroll: true, replace: next.q !== undefined },
        );
    };

    // Searches as the person types, a moment after they stop.
    const [query, setQuery] = useState(filters.q);
    const typed = useRef(false);
    useEffect(() => {
        if (!typed.current) {
            return;
        }
        const timer = window.setTimeout(() => go({ q: query.trim() }), 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const markRead = (data: { ids?: string[]; tipo?: string }) =>
        router.post('/notifications/read', data, { preserveScroll: true, onSuccess: () => setSelected([]) });
    const markUnread = (ids: string[]) => router.post('/notifications/unread', { ids }, { preserveScroll: true, onSuccess: () => setSelected([]) });

    const days = useMemo(() => {
        const groups: { key: string; label: string; items: Notice[] }[] = [];
        notifications.data.forEach((notice) => {
            const key = dayKey(notice.created_at);
            const last = groups[groups.length - 1];
            if (last?.key === key) {
                last.items.push(notice);
            } else {
                groups.push({ key, label: dayLabel(notice.created_at), items: [notice] });
            }
        });

        return groups;
    }, [notifications.data]);

    const pageIds = notifications.data.map((n) => n.id);
    const selectedNotices = notifications.data.filter((n) => selected.includes(n.id));
    const toggle = (id: string, on: boolean) => setSelected((ids) => (on ? [...ids, id] : ids.filter((other) => other !== id)));

    return (
        <AppLayout>
            <Head title="Notificações" />
            <PageHeader
                title="Notificações"
                description="Tudo o que os agentes, as vigilâncias e as pessoas lhe quiseram dizer, num só sítio."
                actions={
                    <>
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/settings/notifications">
                                <Settings2 />
                                Preferências
                            </Link>
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={(current ? current.unread : unreadTotal) === 0}
                            onClick={() => markRead(filters.tipo ? { tipo: filters.tipo } : {})}
                        >
                            <CheckCheck />
                            {current ? `Marcar ${current.label.toLowerCase()} como lidas` : 'Marcar todas como lidas'}
                        </Button>
                    </>
                }
            />

            {awaiting_decision > 0 && filters.tipo !== 'approval' && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border border-status-warning/40 bg-status-warning/10 px-4 py-3 text-sm">
                    <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-lg bg-status-warning/20">
                        <CheckSquare className="size-4" />
                    </span>
                    <span className="min-w-0 flex-1">
                        <span className="font-medium">{plural(awaiting_decision, 'aprovação espera', 'aprovações esperam')} pela sua decisão.</span>{' '}
                        <span className="hidden text-muted-foreground sm:inline">
                            Pagamentos, contratos e acções acima do nível de um agente só avançam com uma pessoa.
                        </span>
                    </span>
                    <Button size="sm" variant="outline" onClick={() => go({ tipo: 'approval', estado: 'todas' })}>
                        Decidir agora
                    </Button>
                </div>
            )}

            <div className="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
                {/* Kinds: a rail on wide screens, a scrolling row of chips on a phone. */}
                <nav
                    aria-label="Tipos de notificação"
                    className="scroll-fade -mx-4 flex gap-1 overflow-x-auto px-4 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0"
                >
                    <KindButton
                        label="Todas"
                        icon={Bell}
                        active={!filters.tipo}
                        unread={unreadTotal}
                        total={total}
                        onClick={() => go({ tipo: null })}
                    />
                    <div className="hidden px-2 pt-4 pb-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase lg:block">
                        Por tipo
                    </div>
                    {categories.map((c) => (
                        <KindButton
                            key={c.key}
                            label={c.label}
                            icon={iconFor(c.key)}
                            active={filters.tipo === c.key}
                            unread={c.unread}
                            total={c.total}
                            urgent={c.key === 'approval' && awaiting_decision > 0}
                            onClick={() => go({ tipo: c.key })}
                        />
                    ))}
                </nav>

                <div className="flex min-w-0 flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <Tabs value={filters.estado} onValueChange={(estado) => go({ estado: estado as Props['filters']['estado'] })}>
                            <TabsList variant="line">
                                <TabsTrigger value="todas">Todas</TabsTrigger>
                                <TabsTrigger value="por-ler">
                                    Por ler
                                    {(current ? current.unread : unreadTotal) > 0 && (
                                        <span className="rounded-full bg-primary/15 px-1.5 font-mono text-[11px] text-foreground tabular-nums">
                                            {number(current ? current.unread : unreadTotal)}
                                        </span>
                                    )}
                                </TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <div className="relative ml-auto w-full sm:w-72">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) => {
                                    typed.current = true;
                                    setQuery(event.target.value);
                                }}
                                onKeyDown={(event) => {
                                    if (event.key === 'Escape' && query !== '') {
                                        typed.current = true;
                                        setQuery('');
                                    }
                                }}
                                placeholder="Pesquisar título, texto ou quem enviou"
                                aria-label="Pesquisar notificações"
                                className="h-8 pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden"
                            />
                            {query !== '' && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        typed.current = true;
                                        setQuery('');
                                    }}
                                    className="absolute top-1/2 right-2 -translate-y-1/2 rounded text-muted-foreground hover:text-foreground"
                                    aria-label="Limpar pesquisa"
                                >
                                    <X className="size-4" />
                                </button>
                            )}
                        </div>
                    </div>

                    {notifications.data.length === 0 ? (
                        <EmptyState
                            icon={filters.q ? SearchX : filters.estado === 'por-ler' ? CheckCheck : BellOff}
                            title={filters.q ? 'Nada encontrado' : filters.estado === 'por-ler' ? 'Tudo lido' : 'Sem notificações'}
                            description={
                                filters.q
                                    ? `Nenhuma notificação${current ? ` de ${current.label.toLowerCase()}` : ''}${filters.estado === 'por-ler' ? ' por ler' : ''} tem «${filters.q}». Experimente outra palavra ou limpe a pesquisa.`
                                    : filters.estado === 'por-ler'
                                      ? 'Não há nada por ler aqui. As lidas continuam no separador «Todas».'
                                      : current
                                        ? `Ainda não recebeu notificações de ${current.label.toLowerCase()}.`
                                        : 'Quando um agente lhe encaminhar algo, pedir uma aprovação ou um prazo se aproximar, aparece aqui.'
                            }
                        />
                    ) : (
                        <>
                            {/* Sticky under the top bar while choosing down the list. */}
                            <div className="sticky top-14 z-20 flex min-h-9 flex-wrap items-center gap-3 rounded-lg bg-background/95 px-4 py-1 text-xs backdrop-blur">
                                <Checkbox
                                    checked={selected.length === 0 ? false : selected.length === pageIds.length ? true : 'indeterminate'}
                                    onCheckedChange={(on) => setSelected(on === true ? pageIds : [])}
                                    aria-label="Seleccionar todas desta página"
                                />
                                <span className="text-muted-foreground">
                                    {selected.length === 0
                                        ? 'Seleccione para marcar várias de uma vez'
                                        : plural(selected.length, 'seleccionada', 'seleccionadas')}
                                </span>
                                {selected.length === 0 && notifications.last_page > 1 && <PagerArrows page={notifications} className="ml-auto" />}
                                {selected.length > 0 && (
                                    <div className="ml-auto flex items-center gap-1.5">
                                        <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                                            Limpar
                                        </Button>
                                        {selectedNotices.some((n) => n.read) && (
                                            <Button size="sm" variant="outline" onClick={() => markUnread(selected)}>
                                                <Mail />
                                                Marcar como por ler
                                            </Button>
                                        )}
                                        <Button
                                            size="sm"
                                            disabled={!selectedNotices.some((n) => !n.read)}
                                            onClick={() => markRead({ ids: selected })}
                                        >
                                            <MailOpen />
                                            Marcar como lidas
                                        </Button>
                                    </div>
                                )}
                            </div>

                            {days.map((day) => (
                                <section key={day.key} className="flex flex-col gap-1.5">
                                    <h2 className="px-1 text-xs font-medium text-muted-foreground">{day.label}</h2>
                                    <div className="flex flex-col gap-2">
                                        {day.items.map((notice) => (
                                            <NoticeRow
                                                key={notice.id}
                                                notice={notice}
                                                selected={selected.includes(notice.id)}
                                                onSelect={(on) => toggle(notice.id, on)}
                                                onToggleRead={() => (notice.read ? markUnread([notice.id]) : markRead({ ids: [notice.id] }))}
                                                kind={current ? undefined : categories.find((c) => c.key === notice.category)?.label}
                                            />
                                        ))}
                                    </div>
                                </section>
                            ))}
                        </>
                    )}
                    {notifications.total > 0 && (
                        <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center">
                            <Pagination page={notifications} noun={['notificação', 'notificações']} className="flex-1" />
                            <label className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                                Por página
                                <NativeSelect
                                    value={filters.por_pagina}
                                    onChange={(event) => go({ por_pagina: Number(event.target.value) })}
                                    className="h-8 w-20 text-xs md:text-xs"
                                    aria-label="Notificações por página"
                                >
                                    {per_page_options.map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </label>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function KindButton({
    label,
    icon: Icon,
    active,
    unread,
    total,
    urgent = false,
    onClick,
}: {
    label: string;
    icon: typeof Bell;
    active: boolean;
    unread: number;
    total: number;
    urgent?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-current={active ? 'page' : undefined}
            className={cn(
                'flex h-8 shrink-0 items-center gap-2 rounded-lg px-2.5 text-sm whitespace-nowrap transition-colors max-lg:border',
                active ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                total === 0 && !active && 'opacity-60',
            )}
        >
            <Icon className="size-4 shrink-0" />
            <span className="truncate lg:flex-1 lg:text-left">{label}</span>
            {unread > 0 && (
                <span
                    className={cn(
                        'rounded-full px-1.5 font-mono text-[11px] text-foreground tabular-nums',
                        urgent ? 'bg-status-warning/20' : 'bg-primary/15',
                    )}
                >
                    {number(unread)}
                </span>
            )}
        </button>
    );
}

function NoticeRow({
    notice,
    selected,
    onSelect,
    onToggleRead,
    kind,
}: {
    notice: Notice;
    selected: boolean;
    onSelect: (on: boolean) => void;
    onToggleRead: () => void;
    kind?: string;
}) {
    const Icon = iconFor(notice.category);
    const tone = levelTone(notice.level);

    return (
        <div
            className={cn(
                'group relative flex items-start gap-3 rounded-lg border bg-card px-4 py-3 transition-colors hover:border-foreground/15 hover:bg-accent/40',
                !notice.read && 'border-primary/20 bg-primary/[0.03]',
                selected && 'border-primary/40 bg-accent/70',
            )}
        >
            <div className="relative z-10 flex items-center gap-2 pt-0.5">
                <Checkbox checked={selected} onCheckedChange={(on) => onSelect(on === true)} aria-label={`Seleccionar «${notice.title}»`} />
            </div>
            <span
                className={cn(
                    'inline-flex size-6 shrink-0 items-center justify-center rounded-md',
                    notice.category === 'approval' || tone === 'warning' ? 'bg-status-warning/15 text-foreground' : 'bg-muted text-muted-foreground',
                )}
            >
                <Icon className="size-3.5" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex items-start gap-2">
                    <Link
                        href={`/notifications/${notice.id}`}
                        className={cn(
                            'min-w-0 flex-1 text-sm leading-snug after:absolute after:inset-0 focus-visible:outline-none',
                            notice.read ? 'font-medium text-muted-foreground' : 'font-semibold text-foreground',
                        )}
                    >
                        <span className="line-clamp-2 sm:line-clamp-1">{notice.title}</span>
                    </Link>
                    <span className="shrink-0 pt-0.5 text-xs text-muted-foreground tabular-nums" title={dateTime(notice.created_at)}>
                        {ago(notice.created_at)}
                    </span>
                </div>
                {/* One line under the title: who, what kind, what they said. */}
                <p className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">
                    {notice.from && <span className="font-medium text-foreground/80">{notice.from}</span>}
                    {notice.from && kind && ' · '}
                    {kind}
                    {(notice.from || kind) && notice.body && ' — '}
                    {notice.body && plainText(notice.body)}
                </p>
                {notice.approval && (
                    <div className="relative z-10 mt-1.5">
                        <ApprovalActions approval={notice.approval} title={notice.title} compact />
                    </div>
                )}
            </div>
            <div className="relative z-10 flex w-6 shrink-0 flex-col items-center gap-1 pt-0.5">
                {!notice.read && <StatusDot tone={tone === 'running' ? 'running' : tone} pulse={false} />}
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon-xs"
                            className="text-muted-foreground opacity-0 group-hover:opacity-100 focus-visible:opacity-100 max-sm:opacity-100"
                            onClick={onToggleRead}
                            aria-label={notice.read ? 'Marcar como por ler' : 'Marcar como lida'}
                        >
                            {notice.read ? <Mail /> : <MailOpen />}
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>{notice.read ? 'Marcar como por ler' : 'Marcar como lida'}</TooltipContent>
                </Tooltip>
            </div>
        </div>
    );
}
