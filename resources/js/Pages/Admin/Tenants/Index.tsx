import { Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';

import { Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AdminLayout from '@/Layouts/AdminLayout';
import { usd } from '@/lib/format';
import { cn } from '@/lib/utils';

interface TenantRow {
    id: number;
    name: string;
    slug: string;
    status: 'active' | 'suspended';
    url: string;
    users: number;
    agents: number;
    spent_usd: number;
    cap_usd: number | null;
}

export default function TenantsIndex({ tenants }: { tenants: TenantRow[] }) {
    return (
        <AdminLayout title="Organizações">
            <PageHeader
                title="Organizações"
                description="Cada organização tem os seus utilizadores, agentes e orçamento de IA, separados das outras."
                actions={
                    <Button asChild>
                        <Link href="/tenants/create">
                            <Plus />
                            Nova organização
                        </Link>
                    </Button>
                }
            />

            {tenants.length === 0 ? (
                <EmptyState
                    icon={Building2}
                    title="Sem organizações"
                    description="Crie a primeira organização para começar a configurar agentes."
                    action={
                        <Button asChild size="sm">
                            <Link href="/tenants/create">
                                <Plus />
                                Nova organização
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table className="table-stack">
                        <TableHeader>
                            <TableRow className="hover:bg-transparent [&>th]:text-xs [&>th]:tracking-wide [&>th]:text-muted-foreground [&>th]:uppercase">
                                <TableHead className="h-9 px-4 text-xs font-medium">Nome</TableHead>
                                <TableHead className="h-9 text-xs font-medium">Endereço</TableHead>
                                <TableHead className="h-9 text-xs font-medium">Estado</TableHead>
                                <TableHead className="h-9 text-right text-xs font-medium">Utilizadores</TableHead>
                                <TableHead className="h-9 text-right text-xs font-medium">Agentes</TableHead>
                                <TableHead className="h-9 px-4 text-right text-xs font-medium">IA este mês</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tenants.map((tenant) => {
                                const over = tenant.cap_usd !== null && tenant.cap_usd > 0 ? tenant.spent_usd / tenant.cap_usd : 0;

                                return (
                                    <TableRow key={tenant.id}>
                                        <TableCell className="px-4 py-2.5">
                                            <Link href={`/tenants/${tenant.id}`} className="flex items-center gap-3 font-medium hover:underline">
                                                <Monogram name={tenant.name} />
                                                <span className="truncate">{tenant.name}</span>
                                            </Link>
                                        </TableCell>
                                        <TableCell data-label="Endereço" className="py-2.5 font-mono text-xs text-muted-foreground">
                                            <a href={tenant.url} target="_blank" rel="noreferrer" className="hover:text-foreground hover:underline">
                                                {tenant.url.replace(/^https?:\/\//, '')}
                                            </a>
                                        </TableCell>
                                        <TableCell data-label="Estado" className="py-2.5">
                                            <StatusBadge tone={tenant.status === 'active' ? 'success' : 'danger'}>
                                                {tenant.status === 'active' ? 'Activa' : 'Suspensa'}
                                            </StatusBadge>
                                        </TableCell>
                                        <TableCell data-label="Utilizadores" className="py-2.5 text-right tabular-nums">{tenant.users}</TableCell>
                                        <TableCell data-label="Agentes" className="py-2.5 text-right tabular-nums">{tenant.agents}</TableCell>
                                        <TableCell data-label="IA este mês" className="px-4 py-2.5 text-right font-mono text-xs tabular-nums">
                                            <span className={cn(over >= 1 && 'text-status-danger', over >= 0.8 && over < 1 && 'text-status-warning')}>
                                                {usd(tenant.spent_usd)}
                                            </span>
                                            {tenant.cap_usd !== null && <span className="text-muted-foreground"> / {usd(tenant.cap_usd)}</span>}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            )}
        </AdminLayout>
    );
}
