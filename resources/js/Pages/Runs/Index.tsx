import { Head, router } from '@inertiajs/react';
import { Activity } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { EntityRow, ListPanel } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, duration, usd } from '@/lib/format';
import type { Paginated, RunSummary } from '@/types';

const statuses = [
    ['', 'Todas'],
    ['running', 'A correr'],
    ['awaiting_approval', 'À espera de aprovação'],
    ['completed', 'Concluídas'],
    ['failed', 'Falhadas'],
];


export default function RunsIndex({ runs, filters }: { runs: Paginated<RunSummary>; filters: { status: string | null } }) {
    return (
        <AppLayout>
            <Head title="Execuções" />
            <PageHeader title="Execuções" description="Tudo o que os agentes fizeram, com custo e resultado." />

            <Tabs value={filters.status ?? ''} onValueChange={(status) => router.get('/runs', status ? { status } : {}, { preserveState: true })}>
                <TabsList variant="line" className="w-full justify-start overflow-x-auto border-b pb-1">
                    {statuses.map(([value, label]) => (
                        <TabsTrigger key={value} value={value} className="flex-none">
                            {label}
                        </TabsTrigger>
                    ))}
                </TabsList>
            </Tabs>

            {runs.data.length === 0 ? (
                <EmptyState
                    icon={Activity}
                    title="Sem execuções"
                    description={
                        filters.status
                            ? 'Nenhuma execução com este estado. Escolha outro separador.'
                            : 'Abra um agente e faça-lhe um pedido; as execuções aparecem aqui assim que começarem.'
                    }
                />
            ) : (
                <div className="flex flex-col gap-4">
                    <ListPanel>
                        {runs.data.map((run) => (
                            <EntityRow
                                key={run.id}
                                href={`/runs/${run.id}`}
                                leading={
                                    <div className="flex items-center gap-3">
                                        <span className="hidden w-10 font-mono text-xs text-muted-foreground tabular-nums sm:block">#{run.id}</span>
                                        <AgentAvatar name={run.agent.name} />
                                    </div>
                                }
                                title={run.input}
                                subtitle={`${run.agent.name} · ${run.trigger_label}${run.requested_by ? ` · ${run.requested_by}` : ''}`}
                                meta={
                                    <>
                                        <span className="hidden w-16 text-right font-mono tabular-nums lg:block">
                                            {duration(run.duration_ms)}
                                        </span>
                                        <span className="w-20 text-right font-mono tabular-nums">{usd(run.cost_usd)}</span>
                                        <span className="w-20 text-right" title={dateTime(run.created_at)}>
                                            {ago(run.created_at)}
                                        </span>
                                    </>
                                }
                                trailing={
                                    <span className="flex justify-end sm:w-44">
                                        <RunStatusBadge status={run.status} label={run.status_label} />
                                    </span>
                                }
                            />
                        ))}
                    </ListPanel>
                    <Pagination page={runs} noun={['execução', 'execuções']} />
                </div>
            )}
        </AppLayout>
    );
}
