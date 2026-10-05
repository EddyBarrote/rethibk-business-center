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
    job_title: string | null;
    email: string;
    role: Role;
    access_role_id: number;
    permission_overrides: Record<string, boolean>;
    department_id: number | null;
    is_active: boolean;
}

interface Props {
    user?: EditableUser;
    roles: Option[];
    access_roles: { id: number; name: string; base: Role; permissions: string[] }[];
    permissions: { value: string; label: string; description: string; group: string }[];
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

export default function UserForm({ user, access_roles, permissions, departments }: Props) {
    const editing = user !== undefined;
    const form = useForm({
        name: user?.name ?? '',
        job_title: user?.job_title ?? '',
        email: user?.email ?? '',
        access_role_id: String(user?.access_role_id ?? access_roles.find((role) => role.base === 'member')?.id ?? ''),
        permission_overrides: (user?.permission_overrides ?? {}) as Record<string, boolean>,
        department_id: user?.department_id ? String(user.department_id) : '',
        is_active: user?.is_active ?? true,
        password: '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            job_title: data.job_title || null,
            access_role_id: data.access_role_id ? Number(data.access_role_id) : null,
            department_id: data.department_id || null,
        }));

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
                    <Field
                        id="job_title"
                        label="Cargo"
                        error={form.errors.job_title}
                        hint="Opcional. Aparece no organigrama, por exemplo CEO ou Contabilista."
                    >
                        <Input id="job_title" value={form.data.job_title} onChange={(e) => form.setData('job_title', e.target.value)} />
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
                        <Field
                            id="access_role_id"
                            label="Papel"
                            error={form.errors.access_role_id ?? (form.errors as Record<string, string | undefined>).role}
                        >
                            <NativeSelect
                                id="access_role_id"
                                value={form.data.access_role_id}
                                onChange={(e) => form.setData('access_role_id', e.target.value)}
                            >
                                {access_roles.map((role) => (
                                    <option key={role.id} value={role.id}>
                                        {role.name}
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
                    title="Excepções"
                    description="Por omissão a pessoa tem o que o papel tem. Aqui pode dar-lhe ou tirar-lhe uma permissão só a ela."
                >
                    <div className="flex flex-col">
                        {permissions.map((permission, index) => {
                            const groupStarts = index === 0 || permissions[index - 1].group !== permission.group;
                            const fromRole =
                                access_roles.find((role) => String(role.id) === form.data.access_role_id)?.permissions.includes(permission.value) ??
                                false;
                            const override = form.data.permission_overrides[permission.value];
                            return (
                                <div key={permission.value}>
                                    {groupStarts && <p className="pt-4 pb-1 text-xs font-semibold text-muted-foreground">{permission.group}</p>}
                                    <div className="flex flex-col gap-2 py-2.5 sm:flex-row sm:items-center">
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-medium">{permission.label}</div>
                                            <div className="text-xs text-muted-foreground">{permission.description}</div>
                                        </div>
                                        <NativeSelect
                                            aria-label={permission.label}
                                            className="sm:w-48"
                                            value={override === undefined ? 'role' : override ? 'allow' : 'deny'}
                                            onChange={(e) => {
                                                const { [permission.value]: _, ...rest } = form.data.permission_overrides;
                                                form.setData(
                                                    'permission_overrides',
                                                    e.target.value === 'role' ? rest : { ...rest, [permission.value]: e.target.value === 'allow' },
                                                );
                                            }}
                                        >
                                            <option value="role">Do papel ({fromRole ? 'sim' : 'não'})</option>
                                            <option value="allow">Permitir</option>
                                            <option value="deny">Negar</option>
                                        </NativeSelect>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <InputError message={(form.errors as Record<string, string | undefined>).permission_overrides} />
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
                    <Button variant="ghost" asChild>
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
