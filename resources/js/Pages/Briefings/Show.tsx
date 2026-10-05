import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, ChevronRight } from 'lucide-react';

import { Properties, Property } from '@/Components/Blocks';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { pathLabel } from '@/lib/paths';
import type { BriefingSummary } from '@/types';

export default function BriefingShow({ briefing }: { briefing: BriefingSummary }) {
    return (
        <AppLayout breadcrumbs={[{ label: 'Briefings', href: '/briefings' }, { label: briefing.title }]}>
            <Head title={briefing.title} />
            <PageHeader
                title={briefing.title}
                description={[briefing.type_label, briefing.agent, dateTime(briefing.created_at)].filter(Boolean).join(' · ')}
            />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-6">
                    {briefing.decisions_pending.length > 0 && (
                        <div className="rounded-xl border border-status-warning/40 bg-status-warning/8 p-4">
                            <p className="mb-2 flex items-center gap-2 text-sm font-semibold">
                                <AlertTriangle className="size-4 text-status-warning" />
                                Precisa da sua decisão
                            </p>
                            <ul className="grid gap-1.5 text-sm">
                                {briefing.decisions_pending.map((d, i) => {
                                    const page = d.link ? pathLabel(d.link) : null;

                                    return (
                                        <li key={i} className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                            <span className="font-medium">{d.title}</span>
                                            {d.owner && <span className="text-xs text-muted-foreground">· {d.owner}</span>}
                                            {d.link && page?.live && (
                                                <Link
                                                    href={d.link}
                                                    className="ml-auto inline-flex items-center gap-0.5 text-xs font-medium text-primary underline-offset-2 hover:underline"
                                                >
                                                    Abrir {page.label}
                                                    <ChevronRight className="size-3.5" />
                                                </Link>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    <article className="rounded-xl border bg-card px-6 py-5">
                        <Markdown>{briefing.content ?? ''}</Markdown>
                    </article>
                </div>

                <Properties className="lg:sticky lg:top-20 lg:self-start">
                    <Property label="Tipo">
                        <span className="rounded-md bg-muted px-1.5 py-0.5 text-xs text-muted-foreground">{briefing.type_label}</span>
                    </Property>
                    <Property label="Estado">
                        {briefing.decisions_pending.length > 0 ? (
                            <StatusBadge tone="warning">
                                {briefing.decisions_pending.length} {briefing.decisions_pending.length === 1 ? 'decisão' : 'decisões'}
                            </StatusBadge>
                        ) : (
                            <StatusBadge tone="success">Sem decisões</StatusBadge>
                        )}
                    </Property>
                    <Property label="Agente">{briefing.agent}</Property>
                    <Property label="Para">{briefing.for}</Property>
                    <Property label="Criado">
                        <span title={dateTime(briefing.created_at)}>{ago(briefing.created_at)}</span>
                    </Property>
                    {briefing.run_id && (
                        <Property label="Execução">
                            <Link href={`/runs/${briefing.run_id}`} className="font-mono text-xs text-primary hover:underline">
                                #{briefing.run_id}
                            </Link>
                        </Property>
                    )}
                    {briefing.highlights.length > 0 && (
                        <div className="mt-2 border-t pt-3">
                            <p className="mb-1.5 text-xs text-muted-foreground">Destaques</p>
                            <ul className="grid gap-1 text-sm">
                                {briefing.highlights.map((h, i) => (
                                    <li key={i} className="flex gap-2">
                                        <span className="mt-2 size-1 shrink-0 rounded-full bg-muted-foreground/60" />
                                        <span>{h}</span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {briefing.run_id && (
                        <Link href={`/runs/${briefing.run_id}`} className="mt-2 border-t pt-3 text-xs text-primary hover:underline">
                            Ver como o agente preparou este briefing
                        </Link>
                    )}
                </Properties>
            </div>
        </AppLayout>
    );
}
