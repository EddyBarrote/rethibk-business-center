import { Head, Link, router } from '@inertiajs/react';
import { ExternalLink, Gavel } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
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

function urgency(deadline: string | null) {
    if (!deadline) return null;
    const hours = (new Date(deadline).getTime() - Date.now()) / 36e5;
    if (hours < 0) return <Badge variant="outline">fechado</Badge>;
    if (hours < 72) return <Badge variant="destructive">{Math.max(1, Math.round(hours))} h</Badge>;
    if (hours < 24 * 10) return <Badge className="bg-amber-500">{Math.round(hours / 24)} dias</Badge>;
    return null;
}

export default function TendersIndex({ tenders, statuses, filter }: { tenders: Paginated<Tender>; statuses: Option[]; filter: 'open' | 'closed' }) {
    return (
        <AppLayout>
            <Head title="Concursos" />
            <PageHeader
                title="Concursos"
                description="Encontrados nas fontes monitorizadas (de hora a hora) e nos emails. O agente de triagem lê cada um e cria a lead quando interessa."
                actions={
                    <>
                        <Button variant={filter === 'open' ? 'default' : 'outline'} size="sm" onClick={() => router.get('/tenders')}>
                            Em aberto
                        </Button>
                        <Button variant={filter === 'closed' ? 'default' : 'outline'} size="sm" onClick={() => router.get('/tenders', { status: 'closed' })}>
                            Fechados
                        </Button>
                    </>
                }
            />

            {tenders.data.length === 0 ? (
                <EmptyState icon={Gavel} title="Sem concursos" description="As fontes de concursos (portais e palavras-chave) são definidas pela Rethink no perfil da organização." />
            ) : (
                <Card className="divide-y py-0">
                    {tenders.data.map((tender) => (
                        <div key={tender.id} className="flex flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center">
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-sm font-medium">{tender.title}</span>
                                    {urgency(tender.deadline_at)}
                                    {tender.erp_lead_id && <Badge variant="secondary">lead {tender.erp_lead_id}</Badge>}
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {[tender.entity, tender.reference, tender.source, tender.deadline_at && `prazo ${dateTime(tender.deadline_at)}`].filter(Boolean).join(' · ')}
                                </p>
                                {tender.summary && <p className="mt-1 line-clamp-2 text-sm text-muted-foreground">{tender.summary}</p>}
                                <div className="mt-1 flex gap-3 text-xs">
                                    {tender.url && (
                                        <a href={tender.url} target="_blank" rel="noreferrer noopener" className="inline-flex items-center gap-1 text-primary hover:underline">
                                            <ExternalLink className="size-3" />
                                            Anúncio
                                        </a>
                                    )}
                                    {tender.email_id && (
                                        <Link href={`/inbox/${tender.email_id}`} className="text-primary hover:underline">
                                            Email de origem
                                        </Link>
                                    )}
                                </div>
                            </div>
                            <NativeSelect className="w-48" value={tender.status} onChange={(e) => router.put(`/tenders/${tender.id}`, { status: e.target.value }, { preserveScroll: true })}>
                                {statuses.map((s) => (
                                    <option key={s.value} value={s.value}>
                                        {s.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                    ))}
                </Card>
            )}
            <Pagination page={tenders} />
        </AppLayout>
    );
}
