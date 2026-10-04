import { Head, router, usePage } from '@inertiajs/react';
import { CheckSquare } from 'lucide-react';

import { ApprovalCard } from '@/Components/ApprovalCard';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { NativeSelect } from '@/Components/ui/native-select';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import type { ApprovalSummary, Paginated, SharedProps } from '@/types';

export default function ApprovalsIndex({ approvals, filters }: { approvals: Paginated<ApprovalSummary>; filters: { status: string } }) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const reload = () => router.reload({ only: ['approvals', 'counts', 'auth'] });

    // Owners and admins hear the tenant channel; everyone hears their own.
    useLive(tenant && auth.user?.can_manage_tenant ? `tenant.${tenant.id}.approvals` : null, ['ApprovalRequested', 'ApprovalDecided'], reload, {
        only: ['approvals', 'counts', 'auth'],
        poll: filters.status === 'pending',
        intervalMs: 10000,
    });
    useLive(tenant && auth.user ? `tenant.${tenant.id}.user.${auth.user.id}` : null, ['ApprovalRequested'], reload);

    return (
        <AppLayout>
            <Head title="Aprovações" />
            <PageHeader
                title="Aprovações"
                description="Acções dos agentes acima do seu nível de autonomia ou sob o tecto absoluto. Nada acontece sem decisão."
                actions={
                    <NativeSelect value={filters.status} onChange={(e) => router.get('/approvals', { status: e.target.value }, { preserveState: true })}>
                        <option value="pending">Pendentes</option>
                        <option value="approved">Aprovadas</option>
                        <option value="rejected">Rejeitadas</option>
                        <option value="all">Todas</option>
                    </NativeSelect>
                }
            />

            {approvals.data.length === 0 ? (
                <EmptyState icon={CheckSquare} title="Nada à espera" description="Quando um agente tentar uma acção acima do seu nível, ela aparece aqui." />
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
