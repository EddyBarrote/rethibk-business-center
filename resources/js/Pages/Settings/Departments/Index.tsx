import { Head, router, useForm } from '@inertiajs/react';
import { Building2, CornerDownRight, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
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

const head = 'h-9 px-4 text-xs font-medium text-muted-foreground';
const TOP = 'top';

/** Departments in tree order (each one followed by its subdepartments), with their depth. */
function tree(departments: DepartmentRow[]): { department: DepartmentRow; depth: number }[] {
    const ids = new Set(departments.map((department) => department.id));
    const children = (parent: number | null) =>
        departments
            .filter((department) =>
                parent === null ? department.parent_id === null || !ids.has(department.parent_id) : department.parent_id === parent,
            )
            .sort((a, b) => a.name.localeCompare(b.name, 'pt'));
    const walk = (parent: number | null, depth: number): { department: DepartmentRow; depth: number }[] =>
        children(parent).flatMap((department) => [{ department, depth }, ...walk(department.id, depth + 1)]);

    return walk(null, 0);
}

export default function DepartmentsIndex({ departments, can }: Props) {
    const [editing, setEditing] = useState<DepartmentRow | null>(null);
    const [open, setOpen] = useState(false);
    const form = useForm({ name: '', slug: '', parent_id: '' });

    const create = () => {
        setEditing(null);
        form.reset();
        form.clearErrors();
        setOpen(true);
    };

    const startEdit = (department: DepartmentRow) => {
        setEditing(department);
        form.clearErrors();
        form.setData({ name: department.name, slug: department.slug, parent_id: department.parent_id ? String(department.parent_id) : '' });
        setOpen(true);
    };

    const submit = () => {
        form.transform((data) => ({ ...data, parent_id: data.parent_id || null }));

        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(`/settings/departments/${editing.id}`, options);
        } else {
            form.post('/settings/departments', options);
        }
    };

    const parents = departments.filter((department) => department.id !== editing?.id);
    const rows = tree(departments);

    return (
        <AppLayout>
            <Head title="Departamentos" />

            <PageHeader
                title="Departamentos"
                description="A estrutura da organização: direcções e subdepartamentos, com as pessoas de cada um."
                actions={
                    can.manage && (
                        <Button onClick={create}>
                            <Plus />
                            Novo departamento
                        </Button>
                    )
                }
            />

            {departments.length === 0 ? (
                <EmptyState
                    icon={Building2}
                    title="Sem departamentos"
                    description={
                        can.manage
                            ? 'Crie a primeira direcção para depois afectar pessoas e agentes.'
                            : 'Peça a um administrador que crie as direcções da organização.'
                    }
                    action={
                        can.manage && (
                            <Button size="sm" onClick={create}>
                                <Plus />
                                Novo departamento
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow className="bg-muted/40 hover:bg-muted/40">
                                <TableHead className={head}>Nome</TableHead>
                                <TableHead className={`${head} hidden sm:table-cell`}>Dentro de</TableHead>
                                <TableHead className={`${head} text-right`}>Pessoas</TableHead>
                                {can.manage && <TableHead className={head} />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map(({ department, depth }) => (
                                <TableRow key={department.id}>
                                    <TableCell className="px-4 py-2">
                                        <div className="flex items-center gap-2" style={{ paddingLeft: `${Math.max(depth - 1, 0) * 1.25}rem` }}>
                                            {depth > 0 && <CornerDownRight className="size-3.5 shrink-0 text-muted-foreground" />}
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">{department.name}</p>
                                                <p className="font-mono text-[11px] text-muted-foreground">{department.slug}</p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell className="hidden px-4 py-2 sm:table-cell">
                                        {department.parent ?? <span className="text-muted-foreground">—</span>}
                                    </TableCell>
                                    <TableCell className="px-4 py-2 text-right font-mono tabular-nums">{department.users_count}</TableCell>
                                    {can.manage && (
                                        <TableCell className="px-4 py-1 text-right whitespace-nowrap">
                                            <Button
                                                variant="ghost"
                                                size="icon-sm"
                                                onClick={() => startEdit(department)}
                                                aria-label={`Editar ${department.name}`}
                                            >
                                                <Pencil />
                                            </Button>
                                            <ConfirmDialog
                                                title={`Remover ${department.name}?`}
                                                description="As pessoas e os subdepartamentos ficam sem departamento. Esta acção não pode ser desfeita."
                                                confirmLabel="Remover"
                                                destructive
                                                onConfirm={() => router.delete(`/settings/departments/${department.id}`, { preserveScroll: true })}
                                                trigger={
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        aria-label={`Remover ${department.name}`}
                                                        className="hover:text-status-danger"
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                }
                                            />
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            )}

            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title={editing ? `Editar ${editing.name}` : 'Novo departamento'}
                description="Uma direcção ou um subdepartamento. O identificador é gerado a partir do nome se ficar vazio."
                submitLabel={editing ? 'Guardar' : 'Criar departamento'}
                processing={form.processing}
                disabled={form.data.name.trim() === ''}
                onSubmit={submit}
            >
                <Field id="name" label="Nome" error={form.errors.name}>
                    <Input
                        id="name"
                        autoFocus
                        placeholder="Direcção Jurídica"
                        value={form.data.name}
                        onChange={(e) => form.setData('name', e.target.value)}
                        aria-invalid={!!form.errors.name}
                    />
                </Field>
                <Field id="slug" label="Identificador" error={form.errors.slug} hint="Opcional. Letras minúsculas e hífenes.">
                    <Input
                        id="slug"
                        className="font-mono"
                        placeholder="direccao-juridica"
                        value={form.data.slug}
                        onChange={(e) => form.setData('slug', e.target.value)}
                        aria-invalid={!!form.errors.slug}
                    />
                </Field>
                <Field id="parent_id" label="Dentro de" error={form.errors.parent_id}>
                    <Select value={form.data.parent_id || TOP} onValueChange={(value) => form.setData('parent_id', value === TOP ? '' : value)}>
                        <SelectTrigger id="parent_id" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={TOP}>Nenhum (topo)</SelectItem>
                            {parents.map((department) => (
                                <SelectItem key={department.id} value={String(department.id)}>
                                    {department.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            </FormDialog>
        </AppLayout>
    );
}
