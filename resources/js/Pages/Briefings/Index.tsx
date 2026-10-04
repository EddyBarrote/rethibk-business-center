import { Head } from '@inertiajs/react';
import { FileText } from 'lucide-react';

import { EntityRow, ListPanel, Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge } from '@/Components/Status';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { BriefingSummary, Paginated } from '@/types';

export default function BriefingsIndex({ briefings }: { briefings: Paginated<BriefingSummary> }) {
    return (
        <AppLayout>
            <Head title="Briefings" />
            <PageHeader title="Briefings" description="Diários (dias úteis às 06:30), semanais (segundas) e pontuais, preparados pelos agentes." />
            {briefings.data.length === 0 ? (
                <EmptyState
                    icon={FileText}
                    title="Ainda sem briefings"
                    description="Quando o Chief of Staff estiver activo, o primeiro briefing chega no próximo dia útil às 06:30."
                />
            ) : (
                <ListPanel>
                    {briefings.data.map((b) => (
                        <EntityRow
                            key={b.id}
                            href={`/briefings/${b.id}`}
                            className="py-3"
                            leading={
                                <span className="relative">
                                    <Monogram name={b.agent ?? 'Chief of Staff'} agent />
                                    {!b.read && (
                                        <span className="absolute -top-0.5 -right-0.5 size-2 rounded-full bg-primary ring-2 ring-card" title="Novo" />
                                    )}
                                </span>
                            }
                            title={<span className={cn(!b.read && 'font-semibold')}>{b.title}</span>}
                            subtitle={
                                b.highlights.length > 0
                                    ? b.highlights.slice(0, 2).join(' · ')
                                    : [b.agent, b.for && `para ${b.for}`].filter(Boolean).join(' · ')
                            }
                            meta={
                                <>
                                    <span className="rounded-md bg-muted px-1.5 py-0.5 text-muted-foreground">{b.type_label}</span>
                                    {b.highlights.length > 0 && (
                                        <span className="hidden lg:inline">{[b.agent, b.for && `para ${b.for}`].filter(Boolean).join(' · ')}</span>
                                    )}
                                    <span className="w-16 text-right tabular-nums" title={dateTime(b.created_at)}>
                                        {ago(b.created_at)}
                                    </span>
                                </>
                            }
                            trailing={
                                b.decisions_pending.length > 0 ? (
                                    <StatusBadge tone="warning">
                                        {b.decisions_pending.length} {b.decisions_pending.length === 1 ? 'decisão' : 'decisões'}
                                    </StatusBadge>
                                ) : undefined
                            }
                        />
                    ))}
                </ListPanel>
            )}
            <Pagination page={briefings} />
        </AppLayout>
    );
}
