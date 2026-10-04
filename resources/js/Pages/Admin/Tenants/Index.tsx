import { Link } from '@inertiajs/react';
import { Building2, Plus } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AdminLayout from '@/Layouts/AdminLayout';
import { usd } from '@/lib/format';

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
                description="Cada organização é um tenant isolado, com os seus utilizadores, agentes e orçamento de IA."
                actions={
                    <Button asChild>
                        <Link href="/tenants/create">
                            <Plus />
                            Nova organização
                        </Link>
                    </Button>
                }
            />

            <Card>
                <CardContent>
                    {tenants.length === 0 ? (
                        <EmptyState icon={Building2} title="Sem organizações" description="Crie a primeira organização para começar a configurar agentes." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nome</TableHead>
                                    <TableHead>Endereço</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead className="text-right">Utilizadores</TableHead>
                                    <TableHead className="text-right">Agentes</TableHead>
                                    <TableHead className="text-right">IA este mês</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tenants.map((tenant) => (
                                    <TableRow key={tenant.id}>
                                        <TableCell>
                                            <Link href={`/tenants/${tenant.id}`} className="font-medium hover:underline">
                                                {tenant.name}
                                            </Link>
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            <a href={tenant.url} target="_blank" rel="noreferrer" className="hover:underline">
                                                {tenant.url.replace(/^https?:\/\//, '')}
                                            </a>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={tenant.status === 'active' ? 'secondary' : 'destructive'}>
                                                {tenant.status === 'active' ? 'Activa' : 'Suspensa'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{tenant.users}</TableCell>
                                        <TableCell className="text-right tabular-nums">{tenant.agents}</TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {usd(tenant.spent_usd)}
                                            {tenant.cap_usd !== null && <span className="text-muted-foreground"> / {usd(tenant.cap_usd)}</span>}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
