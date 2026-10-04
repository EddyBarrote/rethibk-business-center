import { Head, router, useForm } from '@inertiajs/react';
import { Building2, Pencil, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactNode, useRef, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
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
import { Input } from '@/Components/ui/input';
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

const head = 'h-9 px-4 text-xs font-medium tracking-wide text-muted-foreground uppercase';

/** Settings row: what the group is about on the left, its content on the right. */
function SettingsBlock({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="grid gap-4 lg:grid-cols-[16rem_1fr] lg:gap-8">
            <div className="space-y-1">
                <h2 className="text-sm font-semibold">{title}</h2>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            <div className="min-w-0">{children}</div>
        </section>
    );
}

export default function DepartmentsIndex({ departments, can }: Props) {
    const [editing, setEditing] = useState<DepartmentRow | null>(null);
    const form = useForm({ name: '', slug: '', parent_id: '' });
    const formRef = useRef<HTMLFormElement>(null);

    const startEdit = (department: DepartmentRow) => {
        setEditing(department);
        form.clearErrors();
        form.setData({ name: department.name, slug: department.slug, parent_id: department.parent_id ? String(department.parent_id) : '' });
        formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
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

            <SettingsBlock title="Estrutura" description="As direcções e subdepartamentos, com o número de pessoas em cada um.">
                {departments.length === 0 ? (
                    <EmptyState
                        icon={Building2}
                        title="Sem departamentos"
                        description={
                            can.manage
                                ? 'Crie a primeira direcção no formulário abaixo para depois afectar pessoas e agentes.'
                                : 'Peça a um administrador que crie as direcções da organização.'
                        }
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className={head}>Nome</TableHead>
                                    <TableHead className={head}>Dentro de</TableHead>
                                    <TableHead className={`${head} text-right`}>Pessoas</TableHead>
                                    {can.manage && <TableHead className={head} />}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {departments.map((department) => (
                                    <TableRow key={department.id} data-state={editing?.id === department.id ? 'selected' : undefined}>
                                        <TableCell className="px-4 py-2">
                                            <p className="font-medium">{department.name}</p>
                                            <p className="font-mono text-[11px] text-muted-foreground">{department.slug}</p>
                                        </TableCell>
                                        <TableCell className="px-4 py-2">
                                            {department.parent ?? <span className="text-muted-foreground">—</span>}
                                        </TableCell>
                                        <TableCell className="px-4 py-2 text-right font-mono tabular-nums">{department.users_count}</TableCell>
                                        {can.manage && (
                                            <TableCell className="px-4 py-1 text-right whitespace-nowrap">
                                                <Button variant="ghost" size="icon" onClick={() => startEdit(department)} aria-label="Editar">
                                                    <Pencil />
                                                </Button>
                                                <AlertDialog>
                                                    <AlertDialogTrigger asChild>
                                                        <Button variant="ghost" size="icon" aria-label="Remover" className="hover:text-status-danger">
                                                            <Trash2 />
                                                        </Button>
                                                    </AlertDialogTrigger>
                                                    <AlertDialogContent>
                                                        <AlertDialogHeader>
                                                            <AlertDialogTitle>Remover {department.name}?</AlertDialogTitle>
                                                            <AlertDialogDescription>
                                                                As pessoas e subdepartamentos ficam sem departamento. Esta acção não pode ser
                                                                desfeita.
                                                            </AlertDialogDescription>
                                                        </AlertDialogHeader>
                                                        <AlertDialogFooter>
                                                            <AlertDialogCancel>Cancelar</AlertDialogCancel>
                                                            <AlertDialogAction
                                                                onClick={() =>
                                                                    router.delete(`/settings/departments/${department.id}`, { preserveScroll: true })
                                                                }
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
                    </div>
                )}
            </SettingsBlock>

            {can.manage && (
                <SettingsBlock
                    title={editing ? `Editar ${editing.name}` : 'Novo departamento'}
                    description="O identificador é gerado a partir do nome se ficar vazio."
                >
                    <form ref={formRef} onSubmit={submit} className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                        <div className="grid gap-5 sm:grid-cols-2">
                            <Field id="name" label="Nome" error={form.errors.name}>
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(e) => form.setData('name', e.target.value)}
                                    aria-invalid={!!form.errors.name}
                                />
                            </Field>
                            <Field id="slug" label="Identificador" error={form.errors.slug}>
                                <Input
                                    id="slug"
                                    className="font-mono"
                                    value={form.data.slug}
                                    onChange={(e) => form.setData('slug', e.target.value)}
                                    aria-invalid={!!form.errors.slug}
                                />
                            </Field>
                        </div>
                        <Field id="parent_id" label="Dentro de" error={form.errors.parent_id} className="sm:max-w-sm">
                            <NativeSelect id="parent_id" value={form.data.parent_id} onChange={(e) => form.setData('parent_id', e.target.value)}>
                                <option value="">Nenhum (topo)</option>
                                {parents.map((department) => (
                                    <option key={department.id} value={department.id}>
                                        {department.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <div className="flex items-center justify-between gap-2 border-t pt-4">
                            {editing ? (
                                <Button type="button" variant="outline" onClick={reset}>
                                    Cancelar
                                </Button>
                            ) : (
                                <span />
                            )}
                            <Button type="submit" disabled={form.processing}>
                                {editing ? 'Guardar' : 'Criar departamento'}
                            </Button>
                        </div>
                    </form>
                </SettingsBlock>
            )}
        </AppLayout>
    );
}
