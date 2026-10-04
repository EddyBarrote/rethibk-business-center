import { Head, router } from '@inertiajs/react';
import { Check, FileText } from 'lucide-react';

import { EntityRow, ListPanel, Properties, Property, Section } from '@/Components/Blocks';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { ago, date, dateTime, mzn } from '@/lib/format';
import { cn } from '@/lib/utils';
import { purchaseTone, type PurchaseRequestSummary } from '@/Pages/Procurement/Index';
import type { Option } from '@/types';

interface Props {
    request: PurchaseRequestSummary & {
        description: string | null;
        items: { description: string; quantity: number; unit?: string | null }[];
        notes: string | null;
    };
    reports: { id: number; title: string; type: string; created_at: string }[];
    statuses: Option[];
}

export default function ProcurementShow({ request, reports, statuses }: Props) {
    const reached = statuses.findIndex((s) => s.value === request.status);
    const cancelled = request.status === 'cancelled';
    const steps = statuses.filter((s) => s.value !== 'cancelled');

    return (
        <AppLayout breadcrumbs={[{ label: 'Compras', href: '/procurement' }, { label: request.title }]}>
            <Head title={request.title} />
            <PageHeader
                title={request.title}
                description={
                    <span className="inline-flex flex-wrap items-center gap-2">
                        <StatusBadge tone={purchaseTone(request.status)}>{request.status_label}</StatusBadge>
                        <span>
                            {request.requested_by ?? '—'} · <span title={dateTime(request.created_at)}>{ago(request.created_at)}</span>
                        </span>
                    </span>
                }
                actions={
                    request.status !== 'cancelled' && request.status !== 'received' ? (
                        <Button
                            variant="outline"
                            onClick={() => confirm('Cancelar esta requisição?') && router.post(`/procurement/${request.id}/cancel`)}
                        >
                            Cancelar requisição
                        </Button>
                    ) : undefined
                }
            />

            <ol className="flex flex-wrap items-center gap-x-1 gap-y-2 rounded-xl border bg-card px-4 py-3">
                {steps.map((s, index) => {
                    const done = !cancelled && index < reached;
                    const current = !cancelled && index === reached;

                    return (
                        <li key={s.value} className="flex items-center gap-1">
                            {index > 0 && (
                                <span className={cn('mx-1 h-px w-4 sm:w-6', done || current ? 'bg-primary' : 'bg-border')} aria-hidden="true" />
                            )}
                            <span
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                                    current && 'bg-primary text-primary-foreground',
                                    done && 'bg-primary/12 text-primary',
                                    !done && !current && 'bg-muted text-muted-foreground',
                                )}
                            >
                                {done && <Check className="size-3" />}
                                {s.label}
                            </span>
                        </li>
                    );
                })}
                {cancelled && (
                    <li className="ml-auto">
                        <StatusBadge tone="danger">Cancelada</StatusBadge>
                    </li>
                )}
            </ol>

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-8">
                    <Section title="Artigos" action={<span className="text-xs text-muted-foreground tabular-nums">{request.items.length}</span>}>
                        <ListPanel>
                            {request.items.map((item, i) => (
                                <div key={i} className="flex items-center gap-4 px-4 py-2.5 text-sm">
                                    <span className="w-24 shrink-0 text-right font-mono text-muted-foreground tabular-nums">
                                        {item.quantity}
                                        {item.unit ? ` ${item.unit}` : ''}
                                    </span>
                                    <span className="min-w-0 flex-1">{item.description}</span>
                                </div>
                            ))}
                        </ListPanel>
                        {request.description && <p className="text-sm whitespace-pre-wrap text-muted-foreground">{request.description}</p>}
                    </Section>

                    {reports.length > 0 && (
                        <Section title="Documentos">
                            <ListPanel>
                                {reports.map((r) => (
                                    <EntityRow
                                        key={r.id}
                                        href={`/reports/${r.id}`}
                                        leading={
                                            <span className="inline-flex size-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                <FileText className="size-3.5" />
                                            </span>
                                        }
                                        title={r.title}
                                        subtitle={r.type}
                                        meta={<span title={dateTime(r.created_at)}>{ago(r.created_at)}</span>}
                                    />
                                ))}
                            </ListPanel>
                        </Section>
                    )}

                    {request.notes && (
                        <Section title="Histórico">
                            <div className="rounded-xl border bg-card p-4 font-mono text-xs leading-relaxed whitespace-pre-wrap text-muted-foreground">
                                {request.notes}
                            </div>
                        </Section>
                    )}
                </div>

                <Properties>
                    <Property label="Estado">
                        <StatusBadge tone={purchaseTone(request.status)}>{request.status_label}</StatusBadge>
                    </Property>
                    <Property label="Pedida por">{request.requested_by}</Property>
                    <Property label="Departamento">{request.department}</Property>
                    <Property label="Necessário até">{request.needed_by ? date(request.needed_by) : null}</Property>
                    <Property label="Orçamento">
                        {request.budget !== null ? <span className="font-mono tabular-nums">{mzn(request.budget)}</span> : null}
                    </Property>
                    <Property label="Projecto">{request.project_ref && <span className="font-mono">{request.project_ref}</span>}</Property>
                    <Property label="Pedido de cotação">{request.erp_rfq_id && <span className="font-mono">{request.erp_rfq_id}</span>}</Property>
                    <Property label="Nota de encomenda">{request.erp_po_id && <span className="font-mono">{request.erp_po_id}</span>}</Property>
                    <Property label="Criada">
                        <span title={dateTime(request.created_at)}>{date(request.created_at)}</span>
                    </Property>
                </Properties>
            </div>
        </AppLayout>
    );
}
