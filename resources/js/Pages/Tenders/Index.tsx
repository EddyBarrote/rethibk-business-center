import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink, Gavel, Mail } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge, StatusDot, type Tone } from '@/Components/Status';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, Paginated } from '@/types';

interface Tender {
    id: number;
    title: string;
    entity: string | null;
    reference: string | null;
    source: string;
    url: string | null;
    summary: string | null;
    deadline_at: string | null;
    status: string;
    status_label: string;
    email_id: number | null;
    erp_lead_id: string | null;
    keywords: string[];
}

const tenderTone = (status: string): Tone =>
    (({ new: 'warning', reviewing: 'running', bidding: 'running', submitted: 'success', won: 'success', lost: 'danger', discarded: 'idle' })[
        status
    ] as Tone) ?? 'idle';

function urgency(deadline: string | null) {
    if (!deadline) return null;
    const hours = (new Date(deadline).getTime() - Date.now()) / 36e5;
    if (hours < 0) return <StatusBadge tone="idle">fechado</StatusBadge>;
    if (hours < 72) return <StatusBadge tone="danger">{Math.max(1, Math.round(hours))} h</StatusBadge>;
    if (hours < 24 * 10) return <StatusBadge tone="warning">{Math.round(hours / 24)} dias</StatusBadge>;
    return null;
}

const tabs = [
    { value: 'open', label: 'Em aberto', go: () => router.get('/tenders') },
    { value: 'closed', label: 'Fechados', go: () => router.get('/tenders', { status: 'closed' }) },
] as const;

export default function TendersIndex({ tenders, statuses, filter }: { tenders: Paginated<Tender>; statuses: Option[]; filter: 'open' | 'closed' }) {
    return (
        <AppLayout wide>
            <Head title="Concursos" />
            <PageHeader
                title="Concursos"
                description="Encontrados nas fontes monitorizadas (de hora a hora) e nos emails. O agente de triagem lê cada um e cria a lead quando interessa."
                actions={
                    <div className="inline-flex items-center gap-0.5 rounded-lg border bg-card p-0.5">
                        {tabs.map((tab) => (
                            <button
                                key={tab.value}
                                type="button"
                                onClick={tab.go}
                                className={cn(
                                    'inline-flex h-7 items-center rounded-md px-3 text-sm transition-colors',
                                    filter === tab.value ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>
                }
            />

            {tenders.data.length === 0 ? (
                <EmptyState
                    icon={Gavel}
                    title="Sem concursos"
                    description="As fontes de concursos (portais e palavras-chave) são definidas pela Rethink no perfil da organização."
                />
            ) : (
                <div className="divide-y overflow-hidden rounded-xl border bg-card">
                    {tenders.data.map((tender) => (
                        <div key={tender.id} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-start">
                            <StatusDot tone={tenderTone(tender.status)} pulse={false} className="mt-1.5 hidden sm:inline-flex" />
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium">{tender.title}</span>
                                    {urgency(tender.deadline_at)}
                                    {tender.erp_lead_id && (
                                        <span className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                                            lead {tender.erp_lead_id}
                                        </span>
                                    )}
                                </div>
                                <p className="mt-0.5 text-xs text-muted-foreground">
                                    {[tender.entity, tender.source].filter(Boolean).join(' · ')}
                                    {tender.reference && (
                                        <>
                                            {' · '}
                                            <span className="font-mono">{tender.reference}</span>
                                        </>
                                    )}
                                    {tender.deadline_at && (
                                        <>
                                            {' · prazo '}
                                            <span className="tabular-nums" title={ago(tender.deadline_at)}>
                                                {dateTime(tender.deadline_at)}
                                            </span>
                                        </>
                                    )}
                                </p>
                                {tender.summary && <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{tender.summary}</p>}
                                {(tender.url || tender.email_id) && (
                                    <div className="mt-1.5 flex gap-4 text-xs">
                                        {tender.url && (
                                            <a
                                                href={tender.url}
                                                target="_blank"
                                                rel="noreferrer noopener"
                                                className="inline-flex items-center gap-1 text-primary hover:underline"
                                            >
                                                <ExternalLink className="size-3" />
                                                Anúncio
                                            </a>
                                        )}
                                        {tender.email_id && (
                                            <Link
                                                href={`/inbox/${tender.email_id}`}
                                                className="inline-flex items-center gap-1 text-primary hover:underline"
                                            >
                                                <Mail className="size-3" />
                                                Email de origem
                                            </Link>
                                        )}
                                    </div>
                                )}
                            </div>
                            <NativeSelect
                                className="h-8 w-48 shrink-0 text-sm"
                                aria-label="Estado do concurso"
                                value={tender.status}
                                onChange={(e) => router.put(`/tenders/${tender.id}`, { status: e.target.value }, { preserveScroll: true })}
                            >
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    ))}
                </div>
            )}
            <Pagination page={tenders} />
        </AppLayout>
    );
}
