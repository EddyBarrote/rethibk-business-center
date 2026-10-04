import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Printer } from 'lucide-react';

import { Properties, Property } from '@/Components/Blocks';
import { ExportMenu } from '@/Components/ExportMenu';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { ReportStatusBadge, type ReportSummary } from '@/Pages/Reports/Index';
import type { Option } from '@/types';

export default function ReportShow({
    report,
    formats,
}: {
    report: ReportSummary & { content: string; data: Record<string, unknown> | null };
    formats: Option[];
}) {
    return (
        <AppLayout breadcrumbs={[{ label: 'Documentos', href: '/reports' }, { label: report.title }]}>
            <Head title={report.title} />
            <PageHeader
                title={report.title}
                description={[report.type_label, report.agent, report.period].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex gap-2 print:hidden">
                        <ExportMenu action={`/reports/${report.id}/export`} formats={formats} />
                        <Button variant="outline" onClick={() => window.print()}>
                            <Printer />
                            Imprimir
                        </Button>
                        {report.status === 'draft' && (
                            <Button onClick={() => router.post(`/reports/${report.id}/review`)}>
                                <CheckCircle2 />
                                Marcar como revisto
                            </Button>
                        )}
                    </div>
                }
            />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem] print:block">
                <article className="min-w-0 rounded-xl border bg-card px-6 py-5 print:border-0 print:p-0">
                    <Markdown>{report.content}</Markdown>
                </article>

                <Properties className="lg:sticky lg:top-20 lg:self-start print:hidden">
                    <Property label="Estado">
                        <ReportStatusBadge status={report.status} />
                    </Property>
                    <Property label="Tipo">
                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">{report.type_label}</span>
                    </Property>
                    <Property label="Período">{report.period}</Property>
                    <Property label="Referência">{report.subject_ref && <span className="font-mono text-xs">{report.subject_ref}</span>}</Property>
                    <Property label="Agente">{report.agent}</Property>
                    <Property label="Criado">
                        <span title={dateTime(report.created_at)}>{ago(report.created_at)}</span>
                    </Property>
                    {report.status === 'reviewed' && (
                        <>
                            <Property label="Revisto por">{report.reviewed_by}</Property>
                            <Property label="Revisto">
                                <span title={dateTime(report.reviewed_at)}>{ago(report.reviewed_at)}</span>
                            </Property>
                        </>
                    )}
                    {report.run_id && (
                        <>
                            <Property label="Execução">
                                <Link href={`/runs/${report.run_id}`} className="font-mono text-xs text-primary hover:underline">
                                    #{report.run_id}
                                </Link>
                            </Property>
                            <Link href={`/runs/${report.run_id}`} className="mt-2 border-t pt-3 text-xs text-primary hover:underline">
                                Ver como o agente preparou este documento
                            </Link>
                        </>
                    )}
                </Properties>
            </div>
        </AppLayout>
    );
}
