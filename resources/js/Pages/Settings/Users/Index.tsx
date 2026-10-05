import { Head, Link } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';

import { Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Role } from '@/types';

interface UserRow {
    id: number;
    name: string;
    email: string;
    role: Role;
    role_label: string;
    department: string | null;
    is_active: boolean;
    last_seen_at: string | null;
}

const head = 'h-9 px-4 text-xs font-medium tracking-wide text-muted-foreground uppercase';

export default function UsersIndex({ users }: { users: UserRow[] }) {
    const addButton = (
        <Button asChild>
            <Link href="/settings/users/create">
                <Plus />
                Novo utilizador
            </Link>
        </Button>
    );

    return (
        <AppLayout>
            <Head title="Utilizadores" />

            <PageHeader title="Utilizadores" description="Pessoas com acesso à plataforma nesta organização." actions={addButton} />

            {users.length === 0 ? (
                <EmptyState
                    icon={Users}
                    title="Sem utilizadores"
                    description="Convide as pessoas que vão acompanhar e aprovar o trabalho dos agentes."
                    action={addButton}
                />
            ) : (
                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table className="table-stack">
                        <TableHeader>
                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                <TableHead className={head}>Nome</TableHead>
                                <TableHead className={head}>Papel</TableHead>
                                <TableHead className={head}>Departamento</TableHead>
                                <TableHead className={head}>Estado</TableHead>
                                <TableHead className={head}>Última actividade</TableHead>
                                <TableHead className={head} />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users.map((user) => (
                                <TableRow key={user.id} className={cn(!user.is_active && 'text-muted-foreground')}>
                                    <TableCell className="px-4 py-2">
                                        <div className="flex items-center gap-3">
                                            <Monogram name={user.name} />
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{user.name}</p>
                                                <p className="truncate font-mono text-[11px] text-muted-foreground">{user.email}</p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell data-label="Papel" className="px-4 py-2">
                                        {user.role_label}
                                    </TableCell>
                                    <TableCell data-label="Departamento" className="px-4 py-2">
                                        {user.department ?? <span className="text-muted-foreground">—</span>}
                                    </TableCell>
                                    <TableCell data-label="Estado" className="px-4 py-2">
                                        <StatusBadge tone={user.is_active ? 'success' : 'idle'}>{user.is_active ? 'Activo' : 'Inactivo'}</StatusBadge>
                                    </TableCell>
                                    <TableCell data-label="Última actividade" className="px-4 py-2 text-xs whitespace-nowrap text-muted-foreground">
                                        {user.last_seen_at ? <span title={dateTime(user.last_seen_at)}>{ago(user.last_seen_at)}</span> : 'Nunca'}
                                    </TableCell>
                                    <TableCell data-actions className="px-4 py-2 text-right">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/settings/users/${user.id}/edit`}>Editar</Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}
        </AppLayout>
    );
}
