import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Printer } from 'lucide-react';

import { Properties, Property } from '@/Components/Blocks';
import { ExtensionTag } from '@/Components/FileIcon';
import { ExportMenu } from '@/Components/ExportMenu';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, period } from '@/lib/format';
import type { Option } from '@/types';

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
    folder: { id: number; name: string } | null;
    files: { id: number; title: string; format: string; created_at: string }[];
}

export function ReportStatusBadge({ status }: { status: ReportSummary['status'] }) {
    return status === 'draft' ? <StatusBadge tone="warning">Por rever</StatusBadge> : <StatusBadge tone="success">Revisto</StatusBadge>;
}

export default function ReportShow({
    report,
    formats,
}: {
    report: ReportSummary & { content: string; data: Record<string, unknown> | null };
    formats: Option[];
}) {
    return (
        <AppLayout breadcrumbs={[{ label: 'Documentos', href: '/documents' }, { label: report.title }]}>
            <Head title={report.title} />
            <PageHeader
                title={report.title}
                description={[report.type_label, report.agent, period(report.period)].filter(Boolean).join(' · ')}
                actions={
                    <div className="flex flex-wrap gap-2 print:hidden">
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
                <article className="min-w-0 rounded-xl border bg-card px-4 py-4 sm:px-6 sm:py-5 print:border-0 print:p-0">
                    <Markdown>{report.content}</Markdown>
                </article>

                <Properties className="lg:sticky lg:top-20 lg:self-start print:hidden">
                    <Property label="Estado">
                        <ReportStatusBadge status={report.status} />
                    </Property>
                    <Property label="Tipo">
                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">{report.type_label}</span>
                    </Property>
                    <Property label="Período">{report.period && period(report.period)}</Property>
                    <Property label="Referência">{report.subject_ref && <span className="font-mono text-xs">{report.subject_ref}</span>}</Property>
                    <Property label="Agente">{report.agent}</Property>
                    <Property label="Pasta">
                        {report.folder ? (
                            <Link href={`/documents?pasta=${report.folder.id}`} className="hover:underline">
                                {report.folder.name}
                            </Link>
                        ) : (
                            <span className="text-muted-foreground">Sem pasta</span>
                        )}
                    </Property>
                    {report.files.length > 0 && (
                        <Property label="Ficheiros exportados">
                            <ul className="flex flex-col gap-1">
                                {report.files.map((file) => (
                                    <li key={file.id}>
                                        <Link href={`/documents/${file.id}`} className="inline-flex items-center gap-1.5 hover:underline">
                                            <ExtensionTag extension={file.format} />
                                            <span title={dateTime(file.created_at)}>{ago(file.created_at)}</span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </Property>
                    )}
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
                                <Link href={`/settings/runs/${report.run_id}`} className="font-mono text-xs text-primary hover:underline">
                                    #{report.run_id}
                                </Link>
                            </Property>
                            <Link href={`/settings/runs/${report.run_id}`} className="mt-2 border-t pt-3 text-xs text-primary hover:underline">
                                Ver como o agente preparou este documento
                            </Link>
                        </>
                    )}
                </Properties>
            </div>
        </AppLayout>
    );
}
