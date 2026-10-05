import { Head, router, useForm } from '@inertiajs/react';
import { Lock, Plus, Trash2 } from 'lucide-react';
import { type FormEvent, Fragment, useState } from 'react';

import { ConfirmDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
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
    group: string;
}

interface Props {
    roles: AccessRoleRow[];
    permissions: PermissionOption[];
    ceo_keeps: string[];
}

/** Permissions in their areas, in the order the server lists them. */
export function groupPermissions(permissions: PermissionOption[]): [string, PermissionOption[]][] {
    const groups = new Map<string, PermissionOption[]>();
    permissions.forEach((permission) => groups.set(permission.group, [...(groups.get(permission.group) ?? []), permission]));

    return [...groups.entries()];
}

export default function Roles({ roles, permissions, ceo_keeps }: Props) {
    const [creating, setCreating] = useState(false);
    const [removing, setRemoving] = useState<AccessRoleRow | null>(null);

    const toggle = (role: AccessRoleRow, permission: string, on: boolean) =>
        router.put(
            `/settings/roles/${role.id}`,
            { name: role.name, permissions: on ? [...role.permissions, permission] : role.permissions.filter((p) => p !== permission) },
            { preserveScroll: true },
        );

    return (
        <AppLayout>
            <Head title="Papéis e acessos" />

            <PageHeader
                title="Papéis e acessos"
                description="O que cada papel pode fazer no sistema, por área. Cada pessoa tem um papel, e pode ter excepções na sua ficha em Utilizadores. O servidor verifica cada permissão, não só os botões."
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
                            <TableHead className="sticky left-0 z-10 h-auto min-w-56 bg-muted/40 px-4 py-2 text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                Permissão
                            </TableHead>
                            {roles.map((role) => (
                                <TableHead key={role.id} className="h-auto min-w-28 px-2 py-2 text-center align-bottom whitespace-normal">
                                    <div className="flex items-center justify-center gap-1 text-xs font-medium text-foreground">
                                        {role.name}
                                        {role.is_system && (
                                            <span title="Papel de base: não se apaga">
                                                <Lock className="size-3 text-muted-foreground" />
                                            </span>
                                        )}
                                        {!role.is_system && role.users_count === 0 && (
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="size-6"
                                                onClick={() => setRemoving(role)}
                                                aria-label={`Apagar ${role.name}`}
                                            >
                                                <Trash2 className="size-3.5" />
                                            </Button>
                                        )}
                                    </div>
                                    <div className="text-[11px] font-normal text-muted-foreground">
                                        {role.users_count} {role.users_count === 1 ? 'pessoa' : 'pessoas'}
                                    </div>
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {groupPermissions(permissions).map(([group, items]) => (
                            <Fragment key={group}>
                                <TableRow className="bg-muted/20 hover:bg-muted/20">
                                    <TableCell
                                        colSpan={roles.length + 1}
                                        className="sticky left-0 px-4 py-1.5 text-xs font-semibold text-muted-foreground"
                                    >
                                        {group}
                                    </TableCell>
                                </TableRow>
                                {items.map((permission) => (
                                    <TableRow key={permission.value}>
                                        <TableCell className="sticky left-0 z-10 bg-card px-4 py-2 whitespace-normal">
                                            <div className="text-sm font-medium">{permission.label}</div>
                                            <div className="max-w-sm text-xs text-muted-foreground">{permission.description}</div>
                                        </TableCell>
                                        {roles.map((role) => {
                                            const locked = role.key === 'ceo' && ceo_keeps.includes(permission.value);
                                            return (
                                                <TableCell key={role.id} className="px-2 text-center">
                                                    <Checkbox
                                                        checked={locked || role.permissions.includes(permission.value)}
                                                        disabled={locked}
                                                        title={locked ? 'O CEO mantém sempre esta permissão' : undefined}
                                                        onCheckedChange={(checked) => toggle(role, permission.value, checked === true)}
                                                        aria-label={`${role.name}: ${permission.label}`}
                                                    />
                                                </TableCell>
                                            );
                                        })}
                                    </TableRow>
                                ))}
                            </Fragment>
                        ))}
                    </TableBody>
                </Table>
            </div>

            {creating && <NewRoleDialog permissions={permissions} onClose={() => setCreating(false)} />}

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(open) => !open && setRemoving(null)}
                title={`Apagar o papel ${removing?.name ?? ''}?`}
                confirmLabel="Apagar"
                destructive
                onConfirm={() =>
                    removing && router.delete(`/settings/roles/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) })
                }
            />
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
                    <div className="flex max-h-[50vh] flex-col gap-3 overflow-y-auto">
                        {groupPermissions(permissions).map(([group, items]) => (
                            <div key={group} className="flex flex-col gap-2.5">
                                <p className="text-xs font-semibold text-muted-foreground">{group}</p>
                                {items.map((permission) => (
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
