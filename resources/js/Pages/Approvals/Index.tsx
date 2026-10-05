import { Head, router, usePage } from '@inertiajs/react';
import { CheckCheck, CheckSquare, Loader2 } from 'lucide-react';
import { useState } from 'react';

import { ApprovalCard, ApprovalList } from '@/Components/ApprovalCard';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { plural } from '@/lib/format';
import type { ApprovalSummary, Paginated, SharedProps } from '@/types';

const tabs = [
    ['pending', 'Pendentes'],
    ['approved', 'Aprovadas'],
    ['rejected', 'Rejeitadas'],
    ['all', 'Todas'],
] as const;

const empty: Record<string, string> = {
    pending: 'Quando um agente tentar uma acção acima do seu nível de autonomia, ela aparece aqui para decidir.',
    approved: 'Ainda não aprovou nenhuma acção. As pendentes estão no separador ao lado.',
    rejected: 'Ainda não rejeitou nenhuma acção.',
    all: 'Quando um agente tentar uma acção acima do seu nível de autonomia, ela aparece aqui.',
};

interface Props {
    approvals: Paginated<ApprovalSummary>;
    filters: { status: string };
    counts?: { pending: number };
}

export default function ApprovalsIndex({ approvals, filters, counts }: Props) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const reload = () => router.reload({ only: ['approvals', 'counts', 'auth'] });

    // Owners and admins hear the tenant channel; everyone hears their own.
    useLive(tenant && auth.user?.can_manage_tenant ? `tenant.${tenant.id}.approvals` : null, ['ApprovalRequested', 'ApprovalDecided'], reload, {
        only: ['approvals', 'counts', 'auth'],
        poll: filters.status === 'pending',
        intervalMs: 10000,
    });
    useLive(tenant && auth.user ? `tenant.${tenant.id}.user.${auth.user.id}` : null, ['ApprovalRequested'], reload);

    const pending = counts?.pending ?? auth.pending_approvals;
    // Approving several at once is for what may be decided here and is not under the absolute ceiling.
    const bulkable = approvals.data.filter((approval) => approval.can_decide && approval.status === 'pending' && !approval.ceiling_reason);
    const [selected, setSelected] = useState<number[]>([]);
    const [approving, setApproving] = useState(false);
    const approveSelected = () =>
        router.post(
            '/approvals/approve',
            { ids: selected },
            {
                preserveScroll: true,
                onStart: () => setApproving(true),
                onFinish: () => setApproving(false),
                onSuccess: () => setSelected([]),
            },
        );

    return (
        <AppLayout>
            <Head title="Aprovações" />
            <PageHeader
                title="Aprovações"
                description="Acções dos agentes acima do seu nível de autonomia ou sob o tecto absoluto. Nada acontece sem decisão."
            />

            <Tabs value={filters.status} onValueChange={(status) => router.get('/approvals', { status }, { preserveState: true })} className="gap-6">
                <TabsList variant="line" className="scroll-fade w-full justify-start overflow-x-auto border-b pb-1">
                    {tabs.map(([value, label]) => (
                        <TabsTrigger key={value} value={value} className="flex-none">
                            {label}
                            {value === 'pending' && pending > 0 && (
                                <span className="rounded-full bg-status-warning/15 px-1.5 font-mono text-[11px] text-foreground tabular-nums">
                                    {pending}
                                </span>
                            )}
                        </TabsTrigger>
                    ))}
                </TabsList>
            </Tabs>

            {approvals.data.length === 0 ? (
                <EmptyState
                    icon={CheckSquare}
                    title={filters.status === 'pending' ? 'Nada à espera' : 'Sem aprovações'}
                    description={empty[filters.status] ?? empty.all}
                />
            ) : (
                <div className="flex flex-col gap-4">
                    {bulkable.length > 0 && (
                        // Sticky under the top bar, so "Aprovar n" stays in reach while selecting down the list.
                        <div className="sticky top-14 z-20 flex flex-wrap items-center gap-3 rounded-xl border bg-card px-4 py-2.5 text-sm shadow-xs">
                            <Checkbox
                                checked={selected.length === 0 ? false : selected.length === bulkable.length ? true : 'indeterminate'}
                                onCheckedChange={(on) => setSelected(on === true ? bulkable.map((approval) => approval.id) : [])}
                                aria-label="Seleccionar todas"
                            />
                            <span className="text-muted-foreground">
                                {selected.length === 0
                                    ? 'Seleccione para aprovar várias de uma vez'
                                    : plural(selected.length, 'seleccionada', 'seleccionadas')}
                            </span>
                            <span className="hidden text-xs text-muted-foreground md:inline">· as do tecto absoluto decidem-se uma a uma</span>
                            <Button size="sm" className="ml-auto" disabled={selected.length === 0 || approving} onClick={approveSelected}>
                                {approving ? <Loader2 className="animate-spin" /> : <CheckCheck />}
                                Aprovar {selected.length > 0 ? selected.length : ''}
                            </Button>
                        </div>
                    )}
                    {/* On a phone the decision sits at the thumb while rows are selected. */}
                    {selected.length > 0 && (
                        <div className="fixed inset-x-3 bottom-3 z-30 flex items-center gap-3 rounded-xl border bg-card px-4 py-3 text-sm shadow-lg sm:hidden">
                            <span className="min-w-0 flex-1 truncate">{plural(selected.length, 'seleccionada', 'seleccionadas')}</span>
                            <Button size="sm" variant="ghost" onClick={() => setSelected([])}>
                                Limpar
                            </Button>
                            <Button size="sm" disabled={approving} onClick={approveSelected}>
                                {approving ? <Loader2 className="animate-spin" /> : <CheckCheck />}
                                Aprovar {selected.length}
                            </Button>
                        </div>
                    )}
                    <ApprovalList>
                        {approvals.data.map((approval) => (
                            <ApprovalCard
                                key={approval.id}
                                approval={approval}
                                selectable={bulkable.length > 0}
                                selected={selected.includes(approval.id)}
                                onSelectedChange={(on) =>
                                    setSelected((current) => (on ? [...current, approval.id] : current.filter((id) => id !== approval.id)))
                                }
                            />
                        ))}
                    </ApprovalList>
                    <Pagination page={approvals} noun={['aprovação', 'aprovações']} />
                </div>
            )}
        </AppLayout>
    );
}
