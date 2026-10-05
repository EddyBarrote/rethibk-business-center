import { Head, router } from '@inertiajs/react';
import { FileText, Files } from 'lucide-react';

import { EntityRow, ListHeader, ListPanel } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge } from '@/Components/Status';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, period } from '@/lib/format';
import type { Option, Paginated } from '@/types';

export interface ReportSummary {
    id: number;
    type: string;
    type_label: string;
    title: string;
    agent: string | null;
    run_id: number | null;
    subject_ref: string | null;
    period: string | null;
    status: 'draft' | 'reviewed';
    reviewed_by: string | null;
    reviewed_at: string | null;
    created_at: string;
    content?: string;
}

export function ReportStatusBadge({ status }: { status: ReportSummary['status'] }) {
    return status === 'draft' ? <StatusBadge tone="warning">Por rever</StatusBadge> : <StatusBadge tone="success">Revisto</StatusBadge>;
}

export default function ReportsIndex({ reports, types, filter }: { reports: Paginated<ReportSummary>; types: Option[]; filter: string | null }) {
    return (
        <AppLayout>
            <Head title="Documentos" />
            <PageHeader
                title="Documentos"
                description="Preparados pelos agentes para rever: fecho do mês, mapas comparativos, folha de salários, fichas de cliente, briefings de reunião."
                actions={
                    <NativeSelect
                        className="h-8 w-56 text-sm"
                        value={filter ?? ''}
                        onChange={(e) => router.get('/reports', e.target.value ? { type: e.target.value } : {})}
                    >
                        <option value="">Todos os tipos</option>
                        {types.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </NativeSelect>
                }
            />
            {reports.data.length === 0 ? (
                <EmptyState icon={Files} title="Sem documentos" description="Os agentes guardam aqui o que preparam para alguém rever." />
            ) : (
                <ListPanel>
                    <ListHeader
                        leading={<span className="w-4" />}
                        title="Documento"
                        meta={
                            <>
                                <span className="w-28 text-right">Tipo</span>
                                <span className="w-16 text-right">Criado</span>
                            </>
                        }
                        trailing={<span className="w-24 text-right">Estado</span>}
                    />
                    {reports.data.map((r) => (
                        <EntityRow
                            key={r.id}
                            href={`/reports/${r.id}`}
                            leading={<FileText className="size-4 text-muted-foreground" />}
                            title={r.title}
                            subtitle={[r.agent, period(r.period), r.subject_ref].filter(Boolean).join(' · ')}
                            meta={
                                <>
                                    <span className="flex w-28 justify-end">
                                        <span className="truncate rounded-md bg-muted px-1.5 py-0.5 text-muted-foreground">{r.type_label}</span>
                                    </span>
                                    <span className="w-16 text-right tabular-nums" title={dateTime(r.created_at)}>
                                        {ago(r.created_at)}
                                    </span>
                                </>
                            }
                            trailing={
                                <span className="flex justify-end sm:w-24">
                                    <ReportStatusBadge status={r.status} />
                                </span>
                            }
                        />
                    ))}
                </ListPanel>
            )}
            <Pagination page={reports} noun={['documento', 'documentos']} />
        </AppLayout>
    );
}
