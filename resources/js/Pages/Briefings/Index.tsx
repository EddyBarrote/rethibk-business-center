import { Head, Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
import type { BriefingSummary, Paginated } from '@/types';

export default function BriefingsIndex({ briefings }: { briefings: Paginated<BriefingSummary> }) {
    return (
        <AppLayout>
            <Head title="Briefings" />
            <PageHeader title="Briefings" description="Diários (dias úteis às 06:30), semanais (segundas) e pontuais, preparados pelos agentes." />
            {briefings.data.length === 0 ? (
                <EmptyState icon={FileText} title="Ainda sem briefings" description="Quando o Chief of Staff estiver activo, o primeiro briefing chega no próximo dia útil às 06:30." />
            ) : (
                <div className="grid gap-3">
                    {briefings.data.map((b) => (
                        <Link key={b.id} href={`/briefings/${b.id}`}>
                            <Card className="transition-colors hover:border-primary/40">
                                <CardHeader>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary">{b.type_label}</Badge>
                                        {!b.read && <Badge>novo</Badge>}
                                        {b.decisions_pending.length > 0 && <Badge className="bg-amber-500">{b.decisions_pending.length} decisão(ões)</Badge>}
                                        <span className="text-xs text-muted-foreground">
                                            {dateTime(b.created_at)} · {b.agent} · para {b.for}
                                        </span>
                                    </div>
                                    <CardTitle className="mt-1">{b.title}</CardTitle>
                                </CardHeader>
                                {b.highlights.length > 0 && (
                                    <CardContent className="mt-2">
                                        <ul className="list-disc pl-5 text-sm text-muted-foreground">
                                            {b.highlights.slice(0, 3).map((h, i) => (
                                                <li key={i}>{h}</li>
                                            ))}
                                        </ul>
                                    </CardContent>
                                )}
                            </Card>
                        </Link>
                    ))}
                </div>
            )}
            <Pagination page={briefings} />
        </AppLayout>
    );
}
