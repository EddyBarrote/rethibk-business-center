import { Head, router, useForm } from '@inertiajs/react';
import { Lock, Plus, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import AppLayout from '@/Layouts/AppLayout';

interface AccessRoleRow {
    id: number;
    key: string;
    name: string;
    permissions: string[];
    is_system: boolean;
    users_count: number;
}

interface PermissionOption {
    value: string;
    label: string;
    description: string;
}

interface Props {
    roles: AccessRoleRow[];
    permissions: PermissionOption[];
}

export default function Roles({ roles, permissions }: Props) {
    const [creating, setCreating] = useState(false);

    const toggle = (role: AccessRoleRow, permission: string, on: boolean) =>
        router.put(
            `/settings/roles/${role.id}`,
            { name: role.name, permissions: on ? [...role.permissions, permission] : role.permissions.filter((p) => p !== permission) },
            { preserveScroll: true },
        );

    const remove = (role: AccessRoleRow) => router.delete(`/settings/roles/${role.id}`, { preserveScroll: true });

    return (
        <AppLayout wide>
            <Head title="Papéis e acessos" />

            <PageHeader
                title="Papéis e acessos"
                description="O que cada papel pode fazer. Cada pessoa tem um papel, e pode ter excepções na sua ficha em Utilizadores."
                actions={
                    <Button onClick={() => setCreating(true)}>
                        <Plus />
                        Novo papel
                    </Button>
                }
            />

            <div className="overflow-x-auto rounded-xl border bg-card">
                <Table>
                    <TableHeader>
                        <TableRow className="bg-muted/40 hover:bg-muted/40">
                            <TableHead className="h-auto min-w-44 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Papel
                            </TableHead>
                            {permissions.map((permission) => (
                                <TableHead
                                    key={permission.value}
                                    className="h-auto min-w-28 px-2 py-2 text-center align-bottom text-xs font-medium whitespace-normal text-muted-foreground"
                                >
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <span className="cursor-help underline decoration-dotted underline-offset-2">{permission.label}</span>
                                        </TooltipTrigger>
                                        <TooltipContent className="max-w-64">{permission.description}</TooltipContent>
                                    </Tooltip>
                                </TableHead>
                            ))}
                            <TableHead className="w-10" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {roles.map((role) => (
                            <TableRow key={role.id}>
                                <TableCell className="px-4 py-2.5">
                                    <div className="flex items-center gap-1.5 text-sm font-medium">
                                        {role.name}
                                        {role.is_system && (
                                            <span title="Papel de base: não se apaga">
                                                <Lock className="size-3 text-muted-foreground" />
                                            </span>
                                        )}
                                    </div>
                                    <div className="text-xs text-muted-foreground">
                                        {role.users_count} {role.users_count === 1 ? 'pessoa' : 'pessoas'}
                                    </div>
                                </TableCell>
                                {permissions.map((permission) => {
                                    const on = role.permissions.includes(permission.value);
                                    const locked = role.key === 'ceo' && permission.value === 'company.manage';
                                    return (
                                        <TableCell key={permission.value} className="px-2 text-center">
                                            <Checkbox
                                                checked={on}
                                                disabled={locked}
                                                onCheckedChange={(checked) => toggle(role, permission.value, checked === true)}
                                                aria-label={`${role.name}: ${permission.label}`}
                                            />
                                        </TableCell>
                                    );
                                })}
                                <TableCell className="px-2">
                                    {!role.is_system && role.users_count === 0 && (
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            className="size-7"
                                            onClick={() => remove(role)}
                                            aria-label={`Apagar ${role.name}`}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {creating && <NewRoleDialog permissions={permissions} onClose={() => setCreating(false)} />}
        </AppLayout>
    );
}

function NewRoleDialog({ permissions, onClose }: { permissions: PermissionOption[]; onClose: () => void }) {
    const form = useForm({ name: '', permissions: [] as string[] });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/settings/roles', { preserveScroll: true, onSuccess: onClose });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>Novo papel</DialogTitle>
                        <DialogDescription>
                            Por exemplo "Director" ou "Assistente de direcção". Depois atribua-o às pessoas em Utilizadores.
                        </DialogDescription>
                    </DialogHeader>
                    <Field id="name" label="Nome" error={form.errors.name}>
                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                    </Field>
                    <div className="flex flex-col gap-3">
                        {permissions.map((permission) => (
                            <label key={permission.value} className="flex items-start gap-3 text-sm">
                                <Checkbox
                                    className="mt-0.5"
                                    checked={form.data.permissions.includes(permission.value)}
                                    onCheckedChange={(checked) =>
                                        form.setData(
                                            'permissions',
                                            checked === true
                                                ? [...form.data.permissions, permission.value]
                                                : form.data.permissions.filter((p) => p !== permission.value),
                                        )
                                    }
                                />
                                <span>
                                    <span className="font-medium">{permission.label}</span>
                                    <span className="block text-xs text-muted-foreground">{permission.description}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <DialogFooter className="sm:justify-between">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                            Criar papel
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
