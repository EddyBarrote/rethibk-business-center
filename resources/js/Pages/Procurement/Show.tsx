import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';

import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { date, dateTime, mzn } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PurchaseRequestSummary } from '@/Pages/Procurement/Index';
import type { Option } from '@/types';

interface Props {
    request: PurchaseRequestSummary & { description: string | null; items: { description: string; quantity: number; unit?: string | null }[]; notes: string | null };
    reports: { id: number; title: string; type: string; created_at: string }[];
    statuses: Option[];
}

export default function ProcurementShow({ request, reports, statuses }: Props) {
    const reached = statuses.findIndex((s) => s.value === request.status);

    return (
        <AppLayout>
            <Head title={request.title} />
            <div>
                <Link href="/procurement" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Compras
                </Link>
            </div>
            <PageHeader
                title={request.title}
                description={`${request.requested_by ?? ''} · ${dateTime(request.created_at)}`}
                actions={
                    request.status !== 'cancelled' && request.status !== 'received' ? (
                        <Button variant="outline" onClick={() => confirm('Cancelar esta requisição?') && router.post(`/procurement/${request.id}/cancel`)}>
                            Cancelar
                        </Button>
                    ) : undefined
                }
            />

            <div className="flex flex-wrap gap-1">
                {statuses
                    .filter((s) => s.value !== 'cancelled')
                    .map((s, index) => (
                        <span key={s.value} className={cn('rounded-full px-3 py-1 text-xs', index <= reached && request.status !== 'cancelled' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>
                            {s.label}
                        </span>
                    ))}
                {request.status === 'cancelled' && <Badge variant="destructive">Cancelada</Badge>}
            </div>

            <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <Card>
                    <CardHeader>
                        <CardTitle>Artigos</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-2 text-sm">
                        <ul className="grid gap-1">
                            {request.items.map((item, i) => (
                                <li key={i}>
                                    {item.quantity} {item.unit ?? ''} · {item.description}
                                </li>
                            ))}
                        </ul>
                        {request.description && <p className="text-muted-foreground">{request.description}</p>}
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="grid gap-1 text-sm">
                        <p>Necessário até: {date(request.needed_by)}</p>
                        <p>Orçamento: {mzn(request.budget)}</p>
                        {request.project_ref && <p>Projecto: {request.project_ref}</p>}
                        {request.erp_rfq_id && <p>Pedido de cotação: {request.erp_rfq_id}</p>}
                        {request.erp_po_id && <p>Nota de encomenda: {request.erp_po_id}</p>}
                    </CardContent>
                </Card>
            </div>

            {reports.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>Documentos</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-1 text-sm">
                        {reports.map((r) => (
                            <Link key={r.id} href={`/reports/${r.id}`} className="text-primary hover:underline">
                                {r.title} · {dateTime(r.created_at)}
                            </Link>
                        ))}
                    </CardContent>
                </Card>
            )}

            {request.notes && (
                <Card>
                    <CardHeader>
                        <CardTitle>Histórico</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2 text-sm whitespace-pre-wrap text-muted-foreground">{request.notes}</CardContent>
                </Card>
            )}
        </AppLayout>
    );
}
