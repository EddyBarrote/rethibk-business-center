import { Head, router } from '@inertiajs/react';
import { Activity } from 'lucide-react';

import { EntityRow, ListPanel, Monogram, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, usd } from '@/lib/format';
import type { Paginated, RunSummary } from '@/types';

const statuses = [
    ['', 'Todas'],
    ['running', 'A correr'],
    ['awaiting_approval', 'À espera de aprovação'],
    ['completed', 'Concluídas'],
    ['failed', 'Falhadas'],
];

const duration = (ms: number | null) => (ms === null ? null : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`);

export default function RunsIndex({ runs, filters }: { runs: Paginated<RunSummary>; filters: { status: string | null } }) {
    const current = statuses.find(([value]) => value === (filters.status ?? ''))?.[1] ?? 'Todas';

    return (
        <AppLayout>
            <Head title="Execuções" />
            <PageHeader
                title="Execuções"
                description="Tudo o que os agentes fizeram, com custo e resultado."
                actions={
                    <NativeSelect
                        aria-label="Filtrar por estado"
                        value={filters.status ?? ''}
                        onChange={(e) => router.get('/runs', e.target.value ? { status: e.target.value } : {}, { preserveState: true })}
                    >
                        {statuses.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </NativeSelect>
                }
            />

            <Section title={current} action={<span className="text-xs text-muted-foreground tabular-nums">{runs.total} execuções</span>}>
                {runs.data.length === 0 ? (
                    <EmptyState
                        icon={Activity}
                        title="Sem execuções"
                        description={
                            filters.status
                                ? 'Nenhuma execução com este estado. Escolha outro filtro.'
                                : 'Abra um agente e faça-lhe um pedido; as execuções aparecem aqui assim que começarem.'
                        }
                    />
                ) : (
                    <ListPanel>
                        {runs.data.map((run) => (
                            <EntityRow
                                key={run.id}
                                href={`/runs/${run.id}`}
                                leading={
                                    <div className="flex items-center gap-3">
                                        <span className="w-12 font-mono text-xs text-muted-foreground tabular-nums">#{run.id}</span>
                                        <Monogram name={run.agent.name} agent />
                                    </div>
                                }
                                title={run.input}
                                subtitle={`${run.agent.name} · ${run.trigger_label}${run.requested_by ? ` · ${run.requested_by}` : ''}`}
                                meta={
                                    <>
                                        <span className="hidden w-16 text-right font-mono tabular-nums lg:block">
                                            {duration(run.duration_ms) ?? '—'}
                                        </span>
                                        <span className="w-16 text-right font-mono tabular-nums">{usd(run.cost_usd)}</span>
                                        <span className="w-20 text-right" title={dateTime(run.created_at)}>
                                            {ago(run.created_at)}
                                        </span>
                                    </>
                                }
                                trailing={<RunStatusBadge status={run.status} label={run.status_label} />}
                            />
                        ))}
                    </ListPanel>
                )}
            </Section>
            <Pagination page={runs} />
        </AppLayout>
    );
}
