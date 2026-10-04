import { Head, Link } from '@inertiajs/react';
import { Plus, Users } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';
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

const dateFormat = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium', timeStyle: 'short' });

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
                <EmptyState icon={Users} title="Sem utilizadores" description="Convide as pessoas que vão acompanhar e aprovar o trabalho dos agentes." action={addButton} />
            ) : (
                <Card className="py-0">
                    <CardContent className="px-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="pl-6">Nome</TableHead>
                                    <TableHead>Papel</TableHead>
                                    <TableHead>Departamento</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Última actividade</TableHead>
                                    <TableHead className="pr-6" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {users.map((user) => (
                                    <TableRow key={user.id}>
                                        <TableCell className="pl-6">
                                            <p className="font-medium">{user.name}</p>
                                            <p className="text-xs text-muted-foreground">{user.email}</p>
                                        </TableCell>
                                        <TableCell>{user.role_label}</TableCell>
                                        <TableCell>{user.department ?? <span className="text-muted-foreground">—</span>}</TableCell>
                                        <TableCell>
                                            <Badge variant={user.is_active ? 'secondary' : 'outline'}>{user.is_active ? 'Activo' : 'Inactivo'}</Badge>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {user.last_seen_at ? dateFormat.format(new Date(user.last_seen_at)) : 'Nunca'}
                                        </TableCell>
                                        <TableCell className="pr-6 text-right">
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={`/settings/users/${user.id}/edit`}>Editar</Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}
