import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bot,
    Briefcase,
    Check,
    CheckCheck,
    CheckSquare,
    Clock,
    FileSignature,
    Hourglass,
    Inbox,
    Layers,
    Loader2,
    Lock,
    type LucideIcon,
    Mail,
    Search,
    SearchX,
    Settings2,
    ShoppingCart,
    Sparkles,
    Timer,
    Users,
    Wallet,
    X,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { ApprovalCard, ApprovalList } from '@/Components/ApprovalCard';
import { type ApprovalRow, ApprovalPanel, type OpenApproval } from '@/Components/approvals/ApprovalPanel';
import { FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { dayKey, dayLabel } from '@/Components/notifications/meta';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, number, plural } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated, SharedProps } from '@/types';

type View = 'por-decidir' | 'revisao' | 'pendentes' | 'aprovadas' | 'rejeitadas' | 'expiradas' | 'todas';

interface Filters {
    vista: View;
    area: string | null;
    agente: number | null;
    q: string;
    por_pagina: number;
}

interface Stats {
    waiting: number;
    ceiling: number;
    oldest_at: string | null;
    in_review: number;
    approved_7d: number;
    rejected_7d: number;
    median_minutes: number | null;
}

interface Props {
    approvals: Paginated<ApprovalRow>;
    views: { key: View; total: number }[];
    areas: { key: string; label: string; total: number }[];
    agents: { id: number; name: string; total: number }[];
    stats: Stats;
    open: OpenApproval | null;
    filters: Filters;
    per_page_options: number[];
}

const viewMeta: Record<View, { label: string; icon: LucideIcon; empty: [string, string] }> = {
    'por-decidir': {
        label: 'À sua espera',
        icon: Inbox,
        empty: ['Nada à sua espera', 'Quando um agente pedir uma acção que só uma pessoa pode decidir, e a decisão for sua, aparece aqui.'],
    },
    revisao: {
        label: 'Com o Chief of Staff',
        icon: Sparkles,
        empty: ['Nada em revisão', 'O Chief of Staff revê o que os agentes pedem acima do nível antes de chegar às pessoas. Agora não tem nada em mãos.'],
    },
    pendentes: {
        label: 'Todas as pendentes',
        icon: Hourglass,
        empty: ['Nada pendente', 'Nenhuma acção espera decisão, sua ou de outra pessoa.'],
    },
    aprovadas: { label: 'Aprovadas', icon: Check, empty: ['Sem aprovadas', 'As acções aprovadas ficam aqui, com quem decidiu e o que aconteceu depois.'] },
    rejeitadas: { label: 'Rejeitadas', icon: XCircle, empty: ['Sem rejeitadas', 'As acções rejeitadas ficam aqui, com o motivo que o agente leu.'] },
    expiradas: { label: 'Expiradas', icon: Clock, empty: ['Sem expiradas', 'Acções que ninguém decidiu a tempo.'] },
    todas: { label: 'Todas', icon: Layers, empty: ['Sem aprovações', 'Quando um agente tentar uma acção acima do seu nível de autonomia, ela aparece aqui.'] },
};

const areaIcon: Record<string, LucideIcon> = {
    comunicacao: Mail,
    financas: Wallet,
    comercial: Briefcase,
    compras: ShoppingCart,
    pessoas: Users,
    projectos: Layers,
    contratos: FileSignature,
    plataforma: Bot,
    outras: CheckSquare,
};

const live = ['approvals', 'views', 'areas', 'agents', 'stats', 'open', 'auth'];

/** "35 min", "4 h", "2 dias": how long people take to decide. */
function span(minutes: number | null): string {
    if (minutes === null) {
        return '—';
    }
    if (minutes < 60) {
        return `${Math.max(1, minutes)} min`;
    }
    if (minutes < 60 * 48) {
        return `${Math.round(minutes / 60)} h`;
    }

    return `${Math.round(minutes / 1440)} dias`;
}

export default function ApprovalsIndex({ approvals, views, areas, agents, stats, open, filters, per_page_options }: Props) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const reload = () => router.reload({ only: live });

    // Owners and admins hear the tenant channel; everyone hears their own.
    useLive(
        tenant && auth.user?.permissions.includes('approvals.decide_all') ? `tenant.${tenant.id}.approvals` : null,
        ['ApprovalRequested', 'ApprovalDecided'],
        reload,
        { only: live, poll: true, intervalMs: 15000 },
    );
    useLive(tenant && auth.user ? `tenant.${tenant.id}.user.${auth.user.id}` : null, ['ApprovalRequested'], reload);

    // A changed filter starts again at page 1; defaults stay out of the address.
    const params = (merged: Filters, extra: Record<string, unknown> = {}) => ({
        ...(merged.vista !== 'por-decidir' ? { vista: merged.vista } : {}),
        ...(merged.area ? { area: merged.area } : {}),
        ...(merged.agente ? { agente: merged.agente } : {}),
        ...(merged.q ? { q: merged.q } : {}),
        ...(merged.por_pagina !== per_page_options[0] ? { por_pagina: merged.por_pagina } : {}),
        ...extra,
    });
    const go = (next: Partial<Filters>) =>
        router.get('/approvals', params({ ...filters, ...next }), { preserveState: true, preserveScroll: true, replace: next.q !== undefined });

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
    const clearQuery = () => {
        typed.current = true;
        setQuery('');
    };

    // The side panel: opens at once with the row, then fills in where it came from.
    const [openId, setOpenId] = useState<number | null>(open?.id ?? null);
    useEffect(() => setOpenId(open?.id ?? null), [open?.id]);
    const page = new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('page');
    const show = (id: number | null) => {
        setOpenId(id);
        router.get('/approvals', params(filters, { ...(page ? { page } : {}), ...(id ? { aprovacao: id } : {}) }), {
            only: ['open'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };
    const rows = approvals.data;
    const index = rows.findIndex((row) => row.id === openId);
    const shown: ApprovalRow | OpenApproval | null = openId === null ? null : open?.id === openId ? open : (rows[index] ?? null);
    const step = (by: number) => (index >= 0 && rows[index + by] ? () => show(rows[index + by].id) : null);
    // After a decision, the next one still waiting, so a queue is worked through without closing the panel.
    const afterDecision = () => {
        const next = [...rows.slice(index + 1), ...rows.slice(0, Math.max(index, 0))].find((row) => row.id !== openId && row.can_decide && row.status === 'pending');
        show(next?.id ?? null);
    };

    // Bulk decisions: reject covers all that may be decided; approve skips the absolute ceiling and the value limit.
    const selectable = rows.filter((row) => row.can_decide && row.status === 'pending');
    const [selected, setSelected] = useState<number[]>([]);
    useEffect(() => setSelected([]), [filters.vista, filters.area, filters.agente, filters.q, approvals.current_page]);
    const chosen = rows.filter((row) => selected.includes(row.id));
    const approvable = chosen.filter((row) => !row.ceiling_reason && !row.value_limit_reason).map((row) => row.id);
    const [busy, setBusy] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const [reason, setReason] = useState('');
    const bulk = (action: 'approve' | 'reject') =>
        router.post(action === 'approve' ? '/approvals/approve' : '/approvals/reject', action === 'approve' ? { ids: approvable } : { ids: selected, note: reason }, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => setBusy(false),
            onSuccess: () => {
                setSelected([]);
                setRejecting(false);
                setReason('');
            },
        });

    const days = useMemo(() => {
        const groups: { key: string; label: string; items: ApprovalRow[] }[] = [];
        rows.forEach((row) => {
            const key = dayKey(row.created_at);
            const last = groups[groups.length - 1];
            if (last?.key === key) {
                last.items.push(row);
            } else {
                groups.push({ key, label: dayLabel(row.created_at), items: [row] });
            }
        });

        return groups;
    }, [rows]);

    const count = (view: View) => views.find((v) => v.key === view)?.total ?? 0;
    const areaLabel = (key: string) => areas.find((a) => a.key === key)?.label;
    const filtered = Boolean(filters.q || filters.area || filters.agente);
    const empty = viewMeta[filters.vista].empty;

    return (
        <AppLayout>
            <Head title="Aprovações" />
            <PageHeader
                title="Aprovações"
                description="Acções dos agentes acima do seu nível de autonomia ou sob o tecto absoluto. Nada acontece sem decisão."
                actions={
                    <Button variant="ghost" size="sm" asChild>
                        <Link href="/settings/notifications">
                            <Settings2 />
                            Avisos
                        </Link>
                    </Button>
                }
            />

            <div className="grid grid-cols-2 overflow-hidden rounded-xl border bg-card lg:grid-cols-4 [&>*]:border-border max-lg:[&>*:nth-child(-n+2)]:border-b lg:[&>*:not(:first-child)]:border-l max-lg:[&>*:nth-child(even)]:border-l">
                <Stat
                    icon={Inbox}
                    label="À sua espera"
                    value={number(stats.waiting)}
                    tone={stats.waiting > 0 ? 'warning' : undefined}
                    hint={stats.ceiling > 0 ? `${plural(stats.ceiling, 'no tecto absoluto', 'no tecto absoluto')}` : 'nenhuma no tecto absoluto'}
                    onClick={() => go({ vista: 'por-decidir', area: null, agente: null })}
                />
                <Stat
                    icon={Timer}
                    label="A mais antiga"
                    value={stats.oldest_at ? ago(stats.oldest_at).replace(/^há /, '') : '—'}
                    hint={stats.oldest_at ? 'à espera de si' : 'nada à espera'}
                />
                <Stat
                    icon={Sparkles}
                    label="Com o Chief of Staff"
                    value={number(stats.in_review)}
                    hint="a rever antes das pessoas"
                    onClick={() => go({ vista: 'revisao', area: null, agente: null })}
                />
                <Stat
                    icon={CheckCheck}
                    label="Decididas em 7 dias"
                    value={number(stats.approved_7d + stats.rejected_7d)}
                    hint={`${number(stats.approved_7d)} aprovadas · ${number(stats.rejected_7d)} rejeitadas · ${span(stats.median_minutes)} até decidir`}
                />
            </div>

            <div className="grid gap-6 lg:grid-cols-[14rem_minmax(0,1fr)]">
                {/* Views and areas: a rail on wide screens, a scrolling row of chips on a phone. */}
                <nav aria-label="Vistas das aprovações" className="scroll-fade -mx-4 flex gap-1 overflow-x-auto px-4 lg:mx-0 lg:flex-col lg:overflow-visible lg:px-0">
                    {(Object.keys(viewMeta) as View[])
                        .filter((view) => view !== 'expiradas' || count(view) > 0 || filters.vista === view)
                        .map((view) => (
                            <RailButton
                                key={view}
                                label={viewMeta[view].label}
                                icon={viewMeta[view].icon}
                                active={filters.vista === view}
                                count={['por-decidir', 'revisao', 'pendentes'].includes(view) ? count(view) : null}
                                urgent={view === 'por-decidir' && count(view) > 0}
                                onClick={() => go({ vista: view })}
                            />
                        ))}
                    <div className="hidden px-2 pt-4 pb-1 text-[11px] font-medium tracking-wide text-muted-foreground uppercase lg:block">Por área</div>
                    <span className="mx-1 w-px shrink-0 bg-border lg:hidden" aria-hidden="true" />
                    {areas
                        .filter((area) => area.total > 0 || filters.area === area.key)
                        .map((area) => (
                            <RailButton
                                key={area.key}
                                label={area.label}
                                icon={areaIcon[area.key] ?? CheckSquare}
                                active={filters.area === area.key}
                                count={area.total}
                                muted
                                onClick={() => go({ area: filters.area === area.key ? null : area.key })}
                            />
                        ))}
                    {areas.every((area) => area.total === 0) && !filters.area && (
                        <p className="hidden px-2.5 text-xs text-muted-foreground lg:block">Nenhuma nesta vista.</p>
                    )}
                </nav>

                <div className="flex min-w-0 flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative w-full sm:w-auto sm:flex-1 lg:max-w-sm">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                value={query}
                                onChange={(event) => {
                                    typed.current = true;
                                    setQuery(event.target.value);
                                }}
                                onKeyDown={(event) => event.key === 'Escape' && query !== '' && clearQuery()}
                                placeholder="Pesquisar acção, destinatário, agente ou nota"
                                aria-label="Pesquisar aprovações"
                                className="h-8 pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden"
                            />
                            {query !== '' && (
                                <button
                                    type="button"
                                    onClick={clearQuery}
                                    className="absolute top-1/2 right-2 -translate-y-1/2 rounded text-muted-foreground hover:text-foreground"
                                    aria-label="Limpar pesquisa"
                                >
                                    <X className="size-4" />
                                </button>
                            )}
                        </div>
                        <NativeSelect
                            value={filters.agente ?? ''}
                            onChange={(event) => go({ agente: event.target.value ? Number(event.target.value) : null })}
                            className="h-8 text-sm max-sm:flex-1 sm:w-52 md:text-sm"
                            aria-label="Filtrar por agente"
                        >
                            <option value="">Todos os agentes</option>
                            {agents.map((agent) => (
                                <option key={agent.id} value={agent.id}>
                                    {agent.name} ({agent.total})
                                </option>
                            ))}
                        </NativeSelect>
                        {filtered && (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground"
                                onClick={() => {
                                    typed.current = false;
                                    setQuery('');
                                    go({ q: '', area: null, agente: null });
                                }}
                            >
                                Limpar filtros
                            </Button>
                        )}
                    </div>

                    {rows.length === 0 ? (
                        <EmptyState
                            icon={filtered ? SearchX : filters.vista === 'por-decidir' ? CheckCheck : viewMeta[filters.vista].icon}
                            title={filtered ? 'Nada encontrado' : empty[0]}
                            description={
                                filtered
                                    ? `Nenhuma aprovação em «${viewMeta[filters.vista].label}» com estes filtros${filters.q ? ` e «${filters.q}»` : ''}. Experimente outra vista ou limpe os filtros.`
                                    : empty[1]
                            }
                        />
                    ) : (
                        <>
                            {selectable.length > 0 && (
                                // Sticky under the top bar, so the decision stays in reach while choosing down the list.
                                <div className="sticky top-14 z-20 flex flex-wrap items-center gap-3 rounded-xl border bg-card px-4 py-2 text-sm shadow-xs">
                                    <Checkbox
                                        checked={selected.length === 0 ? false : selected.length === selectable.length ? true : 'indeterminate'}
                                        onCheckedChange={(on) => setSelected(on === true ? selectable.map((row) => row.id) : [])}
                                        aria-label="Seleccionar todas desta página"
                                    />
                                    <span className="text-muted-foreground">
                                        {selected.length === 0 ? 'Seleccione para decidir várias de uma vez' : plural(selected.length, 'seleccionada', 'seleccionadas')}
                                    </span>
                                    {selected.length > 0 && approvable.length < selected.length && (
                                        <span className="hidden items-center gap-1 text-xs text-muted-foreground md:inline-flex">
                                            <Lock className="size-3" />
                                            {plural(
                                                selected.length - approvable.length,
                                                'no tecto absoluto ou acima do limite de valor: só se rejeita em bloco',
                                                'no tecto absoluto ou acima do limite de valor: só se rejeitam em bloco',
                                            )}
                                        </span>
                                    )}
                                    {selected.length > 0 && (
                                        <div className="ml-auto flex items-center gap-1.5 max-sm:hidden">
                                            <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                                                Limpar
                                            </Button>
                                            <Button size="sm" variant="outline" disabled={busy} onClick={() => setRejecting(true)}>
                                                <X />
                                                Rejeitar {selected.length}
                                            </Button>
                                            <Button size="sm" disabled={busy || approvable.length === 0} onClick={() => bulk('approve')}>
                                                {busy ? <Loader2 className="animate-spin" /> : <CheckCheck />}
                                                Aprovar {approvable.length}
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            )}
                            {/* On a phone the decision sits at the thumb while rows are selected. */}
                            {selected.length > 0 && (
                                <div className="fixed inset-x-3 bottom-3 z-30 flex items-center gap-2 rounded-xl border bg-card px-3 py-2.5 text-sm shadow-lg sm:hidden">
                                    <span className="min-w-0 flex-1 truncate">{plural(selected.length, 'seleccionada', 'seleccionadas')}</span>
                                    <Button size="sm" variant="outline" disabled={busy} onClick={() => setRejecting(true)}>
                                        <X />
                                        Rejeitar
                                    </Button>
                                    <Button size="sm" disabled={busy || approvable.length === 0} onClick={() => bulk('approve')}>
                                        {busy ? <Loader2 className="animate-spin" /> : <CheckCheck />}
                                        Aprovar {approvable.length}
                                    </Button>
                                </div>
                            )}

                            {days.map((day) => (
                                <section key={day.key} className="flex flex-col gap-2">
                                    <h2 className="px-1 text-xs font-medium text-muted-foreground">{day.label}</h2>
                                    <ApprovalList>
                                        {day.items.map((row) => (
                                            <ApprovalCard
                                                key={row.id}
                                                approval={row}
                                                selectable={selectable.length > 0}
                                                selected={selected.includes(row.id)}
                                                onSelectedChange={(on) => setSelected((ids) => (on ? [...ids, row.id] : ids.filter((id) => id !== row.id)))}
                                                onOpen={() => show(row.id)}
                                                active={row.id === openId}
                                                taskHref={row.task_id ? `/tasks/${row.task_id}?execucao=${row.run_id}` : null}
                                            />
                                        ))}
                                    </ApprovalList>
                                </section>
                            ))}
                        </>
                    )}

                    {approvals.total > 0 && (
                        <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center">
                            <Pagination page={approvals} noun={['aprovação', 'aprovações']} className="flex-1" />
                            <label className="flex items-center justify-center gap-2 text-xs text-muted-foreground">
                                Por página
                                <NativeSelect
                                    value={filters.por_pagina}
                                    onChange={(event) => go({ por_pagina: Number(event.target.value) })}
                                    className="h-8 w-20 text-xs md:text-xs"
                                    aria-label="Aprovações por página"
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

            <ApprovalPanel
                approval={shown}
                loading={open?.id !== openId}
                areaLabel={shown ? areaLabel(shown.area) : undefined}
                position={index >= 0 ? { index: (approvals.current_page - 1) * (approvals.per_page ?? filters.por_pagina) + index, total: approvals.total } : null}
                onPrevious={step(-1)}
                onNext={step(1)}
                onClose={() => show(null)}
                onDecided={afterDecision}
            />

            <FormDialog
                open={rejecting}
                onOpenChange={setRejecting}
                title={`Rejeitar ${plural(selected.length, 'acção', 'acções')}?`}
                description="Cada agente fica a saber porquê e não executa. O mesmo motivo vai para todas."
                submitLabel={`Rejeitar ${selected.length}`}
                processing={busy}
                disabled={reason.trim() === ''}
                onSubmit={() => bulk('reject')}
                size="sm"
            >
                <Field id="bulk-reject-note" label="Motivo" hint="Os agentes lêem o motivo e ajustam o que fazem a seguir.">
                    <Textarea
                        id="bulk-reject-note"
                        rows={3}
                        autoFocus
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                        placeholder="Ex.: estes clientes já foram contactados esta semana."
                    />
                </Field>
            </FormDialog>
        </AppLayout>
    );
}

function Stat({
    icon: Icon,
    label,
    value,
    hint,
    tone,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    hint: string;
    tone?: 'warning';
    onClick?: () => void;
}) {
    const inner = (
        <>
            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Icon className="size-3.5" />
                {label}
            </span>
            <span className={cn('text-xl font-semibold tracking-tight tabular-nums sm:text-2xl', tone === 'warning' && 'text-status-warning')}>{value}</span>
            <span className="line-clamp-1 text-xs text-muted-foreground sm:line-clamp-2">{hint}</span>
        </>
    );
    const className = 'flex min-w-0 flex-col gap-0.5 px-3 py-2.5 text-left sm:gap-1 sm:px-4 sm:py-3';

    return onClick ? (
        <button type="button" onClick={onClick} className={cn(className, 'transition-colors hover:bg-accent/60')}>
            {inner}
        </button>
    ) : (
        <div className={className}>{inner}</div>
    );
}

function RailButton({
    label,
    icon: Icon,
    active,
    count,
    urgent = false,
    muted = false,
    onClick,
}: {
    label: string;
    icon: LucideIcon;
    active: boolean;
    count: number | null;
    urgent?: boolean;
    muted?: boolean;
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
            )}
        >
            <Icon className="size-4 shrink-0" />
            <span className="truncate lg:flex-1 lg:text-left">{label}</span>
            {count !== null && count > 0 && (
                <span
                    className={cn(
                        'rounded-full px-1.5 font-mono text-[11px] text-foreground tabular-nums',
                        urgent ? 'bg-status-warning/20' : muted ? 'bg-muted' : 'bg-primary/15',
                    )}
                >
                    {number(count)}
                </span>
            )}
        </button>
    );
}
