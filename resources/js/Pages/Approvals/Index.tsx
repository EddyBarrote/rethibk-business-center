import { Head, router, usePage } from '@inertiajs/react';
import { CheckSquare } from 'lucide-react';

import { ApprovalCard } from '@/Components/ApprovalCard';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
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

    return (
        <AppLayout>
            <Head title="Aprovações" />
            <PageHeader
                title="Aprovações"
                description="Acções dos agentes acima do seu nível de autonomia ou sob o tecto absoluto. Nada acontece sem decisão."
            />

            <Tabs value={filters.status} onValueChange={(status) => router.get('/approvals', { status }, { preserveState: true })} className="gap-6">
                <TabsList variant="line" className="w-full justify-start border-b pb-1">
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
                <div className="grid gap-3">
                    {approvals.data.map((approval) => (
                        <ApprovalCard key={approval.id} approval={approval} />
                    ))}
                </div>
            )}
            <Pagination page={approvals} />
        </AppLayout>
    );
}
