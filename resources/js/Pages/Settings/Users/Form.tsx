import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardFooter } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import type { Option, Role } from '@/types';

interface EditableUser {
    id: number;
    name: string;
    email: string;
    role: Role;
    department_id: number | null;
    is_active: boolean;
}

interface Props {
    user?: EditableUser;
    roles: Option[];
    departments: { id: number; name: string }[];
}

export default function UserForm({ user, roles, departments }: Props) {
    const editing = user !== undefined;
    const form = useForm({
        name: user?.name ?? '',
        email: user?.email ?? '',
        role: user?.role ?? 'member',
        department_id: user?.department_id ? String(user.department_id) : '',
        is_active: user?.is_active ?? true,
        password: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, department_id: data.department_id || null }));

        if (editing) {
            form.put(`/settings/users/${user.id}`);
        } else {
            form.post('/settings/users');
        }
    };

    return (
        <AppLayout>
            <Head title={editing ? 'Editar utilizador' : 'Novo utilizador'} />

            <PageHeader
                title={editing ? user.name : 'Novo utilizador'}
                description={editing ? 'Dados, papel e departamento.' : 'A pessoa entra com este email e a palavra-passe definida aqui.'}
            />

            <Card className="max-w-2xl">
                <form onSubmit={submit}>
                    <CardContent className="grid gap-5">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nome</Label>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-invalid={!!form.errors.name} />
                            <InputError message={form.errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                value={form.data.email}
                                onChange={(e) => form.setData('email', e.target.value.toLowerCase())}
                                aria-invalid={!!form.errors.email}
                            />
                            <InputError message={form.errors.email} />
                        </div>

                        <div className="grid gap-5 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="role">Papel</Label>
                                <NativeSelect id="role" value={form.data.role} onChange={(e) => form.setData('role', e.target.value as Role)}>
                                    {roles.map((role) => (
                                        <option key={role.value} value={role.value}>
                                            {role.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={form.errors.role} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="department_id">Departamento</Label>
                                <NativeSelect id="department_id" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}>
                                    <option value="">Sem departamento</option>
                                    {departments.map((department) => (
                                        <option key={department.id} value={department.id}>
                                            {department.name}
                                        </option>
                                    ))}
                                </NativeSelect>
                                <InputError message={form.errors.department_id} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">{editing ? 'Nova palavra-passe' : 'Palavra-passe'}</Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                placeholder={editing ? 'Deixe em branco para manter a actual' : undefined}
                                aria-invalid={!!form.errors.password}
                            />
                            <InputError message={form.errors.password} />
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox id="is_active" checked={form.data.is_active} onCheckedChange={(checked) => form.setData('is_active', checked === true)} />
                            <Label htmlFor="is_active" className="font-normal">
                                Conta activa
                            </Label>
                        </div>
                        <InputError message={form.errors.is_active} />
                    </CardContent>

                    <CardFooter className="mt-6 justify-end gap-2">
                        <Button variant="outline" asChild>
                            <Link href="/settings/users">Cancelar</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {editing ? 'Guardar' : 'Criar utilizador'}
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </AppLayout>
    );
}
