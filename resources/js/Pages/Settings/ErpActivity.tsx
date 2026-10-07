import { Head, Link } from '@inertiajs/react';
import { Activity } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

type Result = 'ok' | 'denied' | 'error';

interface Call {
    id: number;
    action: string;
    tool: string | null;
    tool_name: string | null;
    actor_type: 'user' | 'agent' | 'system';
    result: Result;
    duration_ms: number | null;
    error: string | null;
    created_at: string;
}

interface Props {
    calls: Paginated<Call>;
    filters: { result: Result | null };
}

const resultTone: Record<Result, Tone> = { ok: 'success', denied: 'warning', error: 'danger' };
const resultLabel: Record<Result, string> = { ok: 'ok', denied: 'recusada', error: 'erro' };

const actionLabel: Record<string, string> = {
    'erp.tool_call': 'Chamada',
    'erp.tools_list': 'Listagem de ferramentas',
    'erp.connection_test': 'Teste de ligação',
    'erp.connection_updated': 'Configuração alterada',
    'erp.approval_requested': 'Pedido de aprovação',
};

const actorLabel: Record<Call['actor_type'], string> = { user: 'Utilizador', agent: 'Agente', system: 'Sistema' };

const filters: { value: Result | null; label: string }[] = [
    { value: null, label: 'Todas' },
    { value: 'error', label: 'Com erro' },
    { value: 'denied', label: 'Recusadas' },
];

const head = 'h-9 px-4 text-xs font-medium text-muted-foreground';

export default function ErpActivity({ calls, filters: current }: Props) {
    return (
        <SettingsLayout crumbs={[{ label: 'Ligação ao ERP', href: '/settings/integrations/erp' }, { label: 'Actividade' }]}>
            <Head title="Actividade do ERP" />

            <PageHeader
                title="Actividade do ERP"
                description="Todas as operações sobre o ERP, da auditoria: chamadas dos agentes e das pessoas, testes de ligação e alterações à configuração."
            />

            <nav className="flex flex-wrap gap-1" aria-label="Filtrar por resultado">
                {filters.map((filter) => (
                    <Link
                        key={filter.label}
                        href={filter.value ? `/settings/integrations/erp/activity?result=${filter.value}` : '/settings/integrations/erp/activity'}
                        preserveScroll
                        className={cn(
                            'rounded-md px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                            current.result === filter.value && 'bg-muted font-medium text-foreground',
                        )}
                    >
                        {filter.label}
                    </Link>
                ))}
            </nav>

            {calls.data.length === 0 ? (
                <EmptyState
                    icon={Activity}
                    title="Sem operações"
                    description={
                        current.result
                            ? 'Nenhuma operação com este resultado.'
                            : 'Teste a ligação para registar a primeira; as chamadas dos agentes aparecem aqui assim que acontecerem.'
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table className="table-stack">
                        <TableHeader>
                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                <TableHead className={head}>Quando</TableHead>
                                <TableHead className={head}>Operação</TableHead>
                                <TableHead className={head}>Ferramenta</TableHead>
                                <TableHead className={head}>Origem</TableHead>
                                <TableHead className={head}>Resultado</TableHead>
                                <TableHead className={cn(head, 'text-right')}>Duração</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {calls.data.map((call) => (
                                <TableRow key={call.id}>
                                    <TableCell
                                        className="px-4 py-2 text-xs whitespace-nowrap text-muted-foreground"
                                        title={dateTime(call.created_at)}
                                    >
                                        {ago(call.created_at)}
                                    </TableCell>
                                    <TableCell data-label="Operação" className="px-4 py-2">
                                        {actionLabel[call.action] ?? call.action}
                                    </TableCell>
                                    <TableCell data-label="Ferramenta" className="px-4 py-2" title={call.tool ?? undefined}>
                                        {call.tool_name ?? call.tool ?? '—'}
                                    </TableCell>
                                    <TableCell data-label="Origem" className="px-4 py-2 text-muted-foreground">
                                        {actorLabel[call.actor_type]}
                                    </TableCell>
                                    <TableCell data-label="Resultado" className="px-4 py-2">
                                        <StatusBadge tone={resultTone[call.result]} title={call.error ?? undefined}>
                                            {resultLabel[call.result]}
                                        </StatusBadge>
                                    </TableCell>
                                    <TableCell data-label="Duração" className="px-4 py-2 text-right text-xs tabular-nums">
                                        {call.duration_ms !== null ? `${call.duration_ms} ms` : '—'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <Pagination page={calls} noun={['operação', 'operações']} />
        </SettingsLayout>
    );
}
