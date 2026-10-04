import { Head, Link, router } from '@inertiajs/react';
import { Files } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Card } from '@/Components/ui/card';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
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

export default function ReportsIndex({ reports, types, filter }: { reports: Paginated<ReportSummary>; types: Option[]; filter: string | null }) {
    return (
        <AppLayout>
            <Head title="Documentos" />
            <PageHeader
                title="Documentos"
                description="Preparados pelos agentes para rever: fecho do mês, mapas comparativos, folha de salários, fichas de cliente, briefings de reunião."
                actions={
                    <NativeSelect className="w-56" value={filter ?? ''} onChange={(e) => router.get('/reports', e.target.value ? { type: e.target.value } : {})}>
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
                <Card className="divide-y py-0">
                    {reports.data.map((r) => (
                        <Link key={r.id} href={`/reports/${r.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/50">
                            <Badge variant="secondary">{r.type_label}</Badge>
                            <span className="min-w-0 flex-1 truncate text-sm font-medium">{r.title}</span>
                            {r.status === 'draft' ? <Badge className="bg-amber-500">por rever</Badge> : <Badge variant="outline">revisto</Badge>}
                            <span className="text-xs text-muted-foreground">
                                {r.agent} · {dateTime(r.created_at)}
                            </span>
                        </Link>
                    ))}
                </Card>
            )}
            <Pagination page={reports} />
        </AppLayout>
    );
}
