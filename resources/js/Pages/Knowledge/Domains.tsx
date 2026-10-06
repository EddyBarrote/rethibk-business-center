import { Head, router, useForm } from '@inertiajs/react';
import { Check, Lock, Pencil, Plus, Trash2, Users } from 'lucide-react';
import { useState } from 'react';

import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import AppLayout from '@/Layouts/AppLayout';
import { cn } from '@/lib/utils';
import { DomainDot } from '@/Pages/Knowledge/Index';

interface DomainRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    color: string | null;
    department_ids: number[] | null;
    items: number;
    folders: number;
}

interface Props {
    domains: DomainRow[];
    departments: { id: number; name: string }[];
}

const blank = { name: '', description: '', color: '#64748b', restricted: false, department_ids: [] as number[] };

/** Colours that read on both themes; any other can still be picked. */
const swatches = ['#64748b', '#0f766e', '#7c3aed', '#2563eb', '#c2410c', '#be123c', '#a16207', '#15803d'];

export default function KnowledgeDomains({ domains, departments }: Props) {
    const [editing, setEditing] = useState<DomainRow | null>(null);
    const [open, setOpen] = useState(false);
    const form = useForm(blank);
    const departmentName = (id: number) => departments.find((d) => d.id === id)?.name ?? `#${id}`;

    const create = () => {
        setEditing(null);
        form.setData(blank);
        form.clearErrors();
        setOpen(true);
    };

    const edit = (domain: DomainRow) => {
        setEditing(domain);
        form.clearErrors();
        form.setData({
            name: domain.name,
            description: domain.description ?? '',
            color: domain.color ?? '#64748b',
            restricted: domain.department_ids !== null,
            department_ids: domain.department_ids ?? [],
        });
        setOpen(true);
    };

    const submit = () => {
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (editing) {
            form.put(`/knowledge/domains/${editing.id}`, options);
        } else {
            form.post('/knowledge/domains', options);
        }
    };

    const toggleDepartment = (id: number, on: boolean) =>
        form.setData('department_ids', on ? [...form.data.department_ids, id] : form.data.department_ids.filter((d) => d !== id));

    return (
        <AppLayout breadcrumbs={[{ label: 'Conhecimento', href: '/knowledge' }, { label: 'Domínios' }]}>
            <Head title="Domínios de informação" />
            <PageHeader
                title="Domínios de informação"
                description="Cada domínio agrupa um tipo de informação. Um domínio restrito só é visto pelos departamentos escolhidos, pelos proprietários e administradores, e pelos agentes desses departamentos."
                actions={
                    <Button onClick={create}>
                        <Plus />
                        Novo domínio
                    </Button>
                }
            />

            <Section title="Domínios" action={<span className="text-xs text-muted-foreground tabular-nums">{domains.length}</span>}>
                <ListPanel>
                    {domains.map((domain) => (
                        <EntityRow
                            key={domain.id}
                            leading={<DomainDot color={domain.color} className="mx-2.5 size-2.5" />}
                            title={domain.name}
                            subtitle={domain.description}
                            meta={
                                <>
                                    {domain.department_ids === null ? (
                                        <span className="flex items-center gap-1">
                                            <Users className="size-3.5" />
                                            Toda a organização
                                        </span>
                                    ) : (
                                        <StatusBadge
                                            tone="idle"
                                            dot={false}
                                            title={domain.department_ids.map(departmentName).join(', ') || 'Só proprietários e administradores'}
                                        >
                                            <Lock className="size-3" />
                                            {domain.department_ids.length === 0
                                                ? 'Só direcção'
                                                : domain.department_ids.map(departmentName).join(', ')}
                                        </StatusBadge>
                                    )}
                                    <span className="w-24 text-right tabular-nums">
                                        {domain.items} {domain.items === 1 ? 'item' : 'itens'}
                                    </span>
                                </>
                            }
                            trailing={
                                <>
                                    <Button variant="ghost" size="icon-sm" onClick={() => edit(domain)} aria-label={`Editar ${domain.name}`}>
                                        <Pencil />
                                    </Button>
                                    {domain.items > 0 ? (
                                        <Button
                                            variant="ghost"
                                            size="icon-sm"
                                            disabled
                                            aria-label="Apagar"
                                            title="Mova ou apague primeiro os documentos deste domínio"
                                        >
                                            <Trash2 />
                                        </Button>
                                    ) : (
                                        <ConfirmDialog
                                            title={`Apagar o domínio ${domain.name}?`}
                                            description="Está vazio, por isso nenhum documento se perde. As pastas dele também saem."
                                            confirmLabel="Apagar domínio"
                                            destructive
                                            onConfirm={() => router.delete(`/knowledge/domains/${domain.id}`, { preserveScroll: true })}
                                            trigger={
                                                <Button
                                                    variant="ghost"
                                                    size="icon-sm"
                                                    aria-label={`Apagar ${domain.name}`}
                                                    className="hover:text-status-danger"
                                                >
                                                    <Trash2 />
                                                </Button>
                                            }
                                        />
                                    )}
                                </>
                            }
                        />
                    ))}
                </ListPanel>
            </Section>

            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title={editing ? `Editar ${editing.name}` : 'Novo domínio'}
                description="Um tipo de informação da organização, com quem o pode ver."
                submitLabel={editing ? 'Guardar' : 'Criar domínio'}
                processing={form.processing}
                disabled={form.data.name.trim() === ''}
                onSubmit={submit}
            >
                <Field id="name" label="Nome" error={form.errors.name}>
                    <Input id="name" autoFocus value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Jurídico" />
                </Field>
                <Field id="description" label="Descrição" error={form.errors.description}>
                    <Input
                        id="description"
                        value={form.data.description}
                        onChange={(e) => form.setData('description', e.target.value)}
                        placeholder="Contratos, pareceres e processos."
                    />
                </Field>
                <Field id="color" label="Cor" error={form.errors.color}>
                    <div className="flex flex-wrap items-center gap-2">
                        {swatches.map((swatch) => (
                            <button
                                key={swatch}
                                type="button"
                                onClick={() => form.setData('color', swatch)}
                                className={cn(
                                    'flex size-7 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition-shadow',
                                    form.data.color.toLowerCase() === swatch && 'ring-2 ring-ring',
                                )}
                                style={{ backgroundColor: swatch }}
                                aria-label={`Cor ${swatch}`}
                            >
                                {form.data.color.toLowerCase() === swatch && <Check className="size-3.5 text-white" />}
                            </button>
                        ))}
                        <label className="relative flex h-7 items-center gap-1.5 rounded-full border px-2.5 text-xs text-muted-foreground hover:bg-accent">
                            <span className="size-3 rounded-full" style={{ backgroundColor: form.data.color }} />
                            Outra
                            <input
                                id="color"
                                type="color"
                                className="absolute inset-0 cursor-pointer opacity-0"
                                value={form.data.color}
                                onChange={(e) => form.setData('color', e.target.value)}
                            />
                        </label>
                    </div>
                </Field>
                <div className="flex items-start gap-3 rounded-lg border p-3">
                    <Switch id="restricted" checked={form.data.restricted} onCheckedChange={(on) => form.setData('restricted', on)} />
                    <div className="grid gap-1">
                        <Label htmlFor="restricted">Acesso restrito</Label>
                        <p className="text-xs text-muted-foreground">Só os departamentos escolhidos, os proprietários e os administradores.</p>
                    </div>
                </div>
                {form.data.restricted && (
                    <Field id="departments" label="Departamentos com acesso" error={form.errors.department_ids}>
                        <div className="grid gap-2 rounded-lg border p-3 sm:grid-cols-2">
                            {departments.map((department) => (
                                <label key={department.id} className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={form.data.department_ids.includes(department.id)}
                                        onCheckedChange={(on) => toggleDepartment(department.id, on === true)}
                                    />
                                    {department.name}
                                </label>
                            ))}
                        </div>
                    </Field>
                )}
            </FormDialog>
        </AppLayout>
    );
}
