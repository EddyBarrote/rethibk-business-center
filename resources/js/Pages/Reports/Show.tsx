import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Printer } from 'lucide-react';

import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
import type { ReportSummary } from '@/Pages/Reports/Index';

export default function ReportShow({ report }: { report: ReportSummary & { content: string; data: Record<string, unknown> | null } }) {
    return (
        <AppLayout>
            <Head title={report.title} />
            <div className="print:hidden">
                <Link href="/reports" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Documentos
                </Link>
            </div>
            <PageHeader
                title={report.title}
                description={[report.type_label, report.agent, report.period, dateTime(report.created_at)].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex gap-2 print:hidden">
                        <Button variant="outline" onClick={() => window.print()}>
                            <Printer />
                            Imprimir
                        </Button>
                        {report.status === 'draft' ? (
                            <Button onClick={() => router.post(`/reports/${report.id}/review`)}>
                                <CheckCircle2 />
                                Marcar como revisto
                            </Button>
                        ) : (
                            <Badge variant="outline">Revisto por {report.reviewed_by} · {dateTime(report.reviewed_at)}</Badge>
                        )}
                    </div>
                }
            />
            <Card>
                <CardContent>
                    <Markdown>{report.content}</Markdown>
                </CardContent>
            </Card>
            {report.run_id && (
                <Link href={`/runs/${report.run_id}`} className="text-sm text-primary hover:underline print:hidden">
                    Ver como o agente preparou este documento
                </Link>
            )}
        </AppLayout>
    );
}
