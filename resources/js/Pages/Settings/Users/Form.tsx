import { Head, Link, useForm } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';

import { Field } from '@/Components/Field';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
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

/** Settings row: what the group is about on the left, its controls in a bordered block on the right. */
function SettingsBlock({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="grid gap-4 lg:grid-cols-[16rem_1fr] lg:gap-8">
            <div className="space-y-1">
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            <div className="flex min-w-0 flex-col gap-5 rounded-xl border bg-card p-5">{children}</div>
        </section>
    );
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
        <AppLayout breadcrumbs={[{ label: 'Utilizadores', href: '/settings/users' }, { label: editing ? user.name : 'Novo utilizador' }]}>
            <Head title={editing ? 'Editar utilizador' : 'Novo utilizador'} />

            <PageHeader
                title={editing ? user.name : 'Novo utilizador'}
                description={editing ? 'Dados, papel e departamento.' : 'A pessoa entra com este email e a palavra-passe definida aqui.'}
            />

            <form onSubmit={submit} className="flex flex-col gap-8">
                <SettingsBlock title="Perfil" description="Como a pessoa aparece na plataforma e o email com que entra.">
                    <Field id="name" label="Nome" error={form.errors.name}>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            aria-invalid={!!form.errors.name}
                        />
                    </Field>
                    <Field id="email" label="Email" error={form.errors.email}>
                        <Input
                            id="email"
                            type="email"
                            value={form.data.email}
                            onChange={(e) => form.setData('email', e.target.value.toLowerCase())}
                            aria-invalid={!!form.errors.email}
                        />
                    </Field>
                </SettingsBlock>

                <SettingsBlock title="Acesso" description="O papel define o que a pessoa pode ver e aprovar; o departamento, que agentes acompanha.">
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field id="role" label="Papel" error={form.errors.role}>
                            <NativeSelect id="role" value={form.data.role} onChange={(e) => form.setData('role', e.target.value as Role)}>
                                {roles.map((role) => (
                                    <option key={role.value} value={role.value}>
                                        {role.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field id="department_id" label="Departamento" error={form.errors.department_id}>
                            <NativeSelect
                                id="department_id"
                                value={form.data.department_id}
                                onChange={(e) => form.setData('department_id', e.target.value)}
                            >
                                <option value="">Sem departamento</option>
                                {departments.map((department) => (
                                    <option key={department.id} value={department.id}>
                                        {department.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                    </div>
                    <div className="grid gap-1">
                        <div className="flex items-start gap-3">
                            <Checkbox
                                id="is_active"
                                className="mt-0.5"
                                checked={form.data.is_active}
                                onCheckedChange={(checked) => form.setData('is_active', checked === true)}
                            />
                            <div className="grid gap-0.5">
                                <Label htmlFor="is_active">Conta activa</Label>
                                <p className="text-xs text-muted-foreground">Uma conta inactiva deixa de poder entrar, mas mantém o histórico.</p>
                            </div>
                        </div>
                        <InputError message={form.errors.is_active} />
                    </div>
                </SettingsBlock>

                <SettingsBlock
                    title="Palavra-passe"
                    description={
                        editing
                            ? 'Só preencha se quiser definir uma nova palavra-passe.'
                            : 'A palavra-passe com que a pessoa entra pela primeira vez.'
                    }
                >
                    <Field id="password" label={editing ? 'Nova palavra-passe' : 'Palavra-passe'} error={form.errors.password}>
                        <Input
                            id="password"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.password}
                            onChange={(e) => form.setData('password', e.target.value)}
                            placeholder={editing ? 'Deixe em branco para manter a actual' : undefined}
                            aria-invalid={!!form.errors.password}
                        />
                    </Field>
                </SettingsBlock>

                <div className="flex items-center justify-between gap-2 border-t pt-5">
                    <Button variant="outline" asChild>
                        <Link href="/settings/users">Cancelar</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {editing ? 'Guardar' : 'Criar utilizador'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
