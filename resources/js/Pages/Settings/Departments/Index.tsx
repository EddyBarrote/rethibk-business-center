import { Head, router, useForm } from '@inertiajs/react';
import { Building2, Pencil, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Components/ui/alert-dialog';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';

interface DepartmentRow {
    id: number;
    name: string;
    slug: string;
    parent_id: number | null;
    parent: string | null;
    users_count: number;
}

interface Props {
    departments: DepartmentRow[];
    can: { manage: boolean };
}

export default function DepartmentsIndex({ departments, can }: Props) {
    const [editing, setEditing] = useState<DepartmentRow | null>(null);
    const form = useForm({ name: '', slug: '', parent_id: '' });

    const startEdit = (department: DepartmentRow) => {
        setEditing(department);
        form.clearErrors();
        form.setData({ name: department.name, slug: department.slug, parent_id: department.parent_id ? String(department.parent_id) : '' });
    };

    const reset = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, parent_id: data.parent_id || null }));

        const options = { preserveScroll: true, onSuccess: reset };

        if (editing) {
            form.put(`/settings/departments/${editing.id}`, options);
        } else {
            form.post('/settings/departments', options);
        }
    };

    const parents = departments.filter((department) => department.id !== editing?.id);

    return (
        <AppLayout>
            <Head title="Departamentos" />

            <PageHeader title="Departamentos" description="A estrutura da organização. Cada agente responde a uma direcção." />

            <div className="grid gap-6 lg:grid-cols-3">
                <div className="lg:col-span-2">
                    {departments.length === 0 ? (
                        <EmptyState icon={Building2} title="Sem departamentos" description="Crie as direcções da organização para depois afectar pessoas e agentes." />
                    ) : (
                        <Card className="py-0">
                            <CardContent className="px-0">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="pl-6">Nome</TableHead>
                                            <TableHead>Dentro de</TableHead>
                                            <TableHead>Pessoas</TableHead>
                                            {can.manage && <TableHead className="pr-6" />}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {departments.map((department) => (
                                            <TableRow key={department.id}>
                                                <TableCell className="pl-6">
                                                    <p className="font-medium">{department.name}</p>
                                                    <p className="text-xs text-muted-foreground">{department.slug}</p>
                                                </TableCell>
                                                <TableCell>{department.parent ?? <span className="text-muted-foreground">—</span>}</TableCell>
                                                <TableCell>{department.users_count}</TableCell>
                                                {can.manage && (
                                                    <TableCell className="pr-6 text-right">
                                                        <Button variant="ghost" size="icon" onClick={() => startEdit(department)} aria-label="Editar">
                                                            <Pencil />
                                                        </Button>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button variant="ghost" size="icon" aria-label="Remover">
                                                                    <Trash2 />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Remover {department.name}?</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        As pessoas e subdepartamentos ficam sem departamento. Esta acção não pode ser desfeita.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        onClick={() => router.delete(`/settings/departments/${department.id}`, { preserveScroll: true })}
                                                                    >
                                                                        Remover
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </TableCell>
                                                )}
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </CardContent>
                        </Card>
                    )}
                </div>

                {can.manage && (
                    <Card className="h-fit">
                        <CardHeader>
                            <CardTitle>{editing ? `Editar ${editing.name}` : 'Novo departamento'}</CardTitle>
                            <CardDescription>O identificador é gerado a partir do nome se ficar vazio.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Nome</Label>
                                    <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-invalid={!!form.errors.name} />
                                    <InputError message={form.errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="slug">Identificador</Label>
                                    <Input id="slug" value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value)} aria-invalid={!!form.errors.slug} />
                                    <InputError message={form.errors.slug} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="parent_id">Dentro de</Label>
                                    <NativeSelect id="parent_id" value={form.data.parent_id} onChange={(e) => form.setData('parent_id', e.target.value)}>
                                        <option value="">Nenhum (topo)</option>
                                        {parents.map((department) => (
                                            <option key={department.id} value={department.id}>
                                                {department.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <InputError message={form.errors.parent_id} />
                                </div>
                                <div className="flex justify-end gap-2">
                                    {editing && (
                                        <Button type="button" variant="outline" onClick={reset}>
                                            Cancelar
                                        </Button>
                                    )}
                                    <Button type="submit" disabled={form.processing}>
                                        {editing ? 'Guardar' : 'Criar'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
