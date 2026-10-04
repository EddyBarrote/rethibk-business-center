import { Head, router } from '@inertiajs/react';
import { Activity } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { Card, CardContent } from '@/Components/ui/card';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime, usd } from '@/lib/format';
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
            <PageHeader
                title="Execuções"
                description="Tudo o que os agentes fizeram, com custo e resultado."
                actions={
                    <NativeSelect value={filters.status ?? ''} onChange={(e) => router.get('/runs', e.target.value ? { status: e.target.value } : {}, { preserveState: true })}>
                        {statuses.map(([value, label]) => (
                            <option key={value} value={value}>
                                {label}
                            </option>
                        ))}
                    </NativeSelect>
                }
            />
            <Card>
                <CardContent className="grid gap-4">
                    {runs.data.length === 0 ? (
                        <EmptyState icon={Activity} title="Sem execuções" description="As execuções dos agentes aparecem aqui." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>#</TableHead>
                                    <TableHead>Agente</TableHead>
                                    <TableHead>Pedido</TableHead>
                                    <TableHead>Origem</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead className="text-right">Custo</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {runs.data.map((run) => (
                                    <TableRow key={run.id} className="cursor-pointer" onClick={() => router.visit(`/runs/${run.id}`)}>
                                        <TableCell className="tabular-nums">{run.id}</TableCell>
                                        <TableCell className="font-medium">{run.agent.name}</TableCell>
                                        <TableCell className="max-w-sm">
                                            <p className="truncate">{run.input}</p>
                                            <p className="text-xs text-muted-foreground">{dateTime(run.created_at)}</p>
                                        </TableCell>
                                        <TableCell>{run.trigger_label}</TableCell>
                                        <TableCell>
                                            <RunStatusBadge status={run.status} label={run.status_label} />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{usd(run.cost_usd)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                    <Pagination page={runs} />
                </CardContent>
            </Card>
        </AppLayout>
    );
}
