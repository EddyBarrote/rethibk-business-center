import { Head, router, useForm } from '@inertiajs/react';
import { Lock, Pencil, Trash2, Users } from 'lucide-react';
import { type FormEvent, useRef, useState } from 'react';

import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import AppLayout from '@/Layouts/AppLayout';
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

export default function KnowledgeDomains({ domains, departments }: Props) {
    const [editing, setEditing] = useState<DomainRow | null>(null);
    const form = useForm(blank);
    const formRef = useRef<HTMLFormElement>(null);
    const departmentName = (id: number) => departments.find((d) => d.id === id)?.name ?? `#${id}`;

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
        formRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    };

    const reset = () => {
        setEditing(null);
        form.setData(blank);
        form.clearErrors();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: reset };

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
            />

            <Section title="Domínios">
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
                                    <Button variant="ghost" size="icon" onClick={() => edit(domain)} aria-label="Editar">
                                        <Pencil />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Apagar"
                                        className="hover:text-status-danger"
                                        disabled={domain.items > 0}
                                        title={domain.items > 0 ? 'Mova ou apague primeiro os documentos' : undefined}
                                        onClick={() => router.delete(`/knowledge/domains/${domain.id}`, { preserveScroll: true })}
                                    >
                                        <Trash2 />
                                    </Button>
                                </>
                            }
                        />
                    ))}
                </ListPanel>
            </Section>

            <Section title={editing ? `Editar ${editing.name}` : 'Novo domínio'}>
                <form ref={formRef} onSubmit={submit} className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                    <div className="grid gap-5 sm:grid-cols-[1fr_8rem]">
                        <Field id="name" label="Nome" error={form.errors.name}>
                            <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Jurídico" />
                        </Field>
                        <Field id="color" label="Cor" error={form.errors.color}>
                            <Input
                                id="color"
                                type="color"
                                className="h-9 p-1"
                                value={form.data.color}
                                onChange={(e) => form.setData('color', e.target.value)}
                            />
                        </Field>
                    </div>
                    <Field id="description" label="Descrição" error={form.errors.description}>
                        <Input
                            id="description"
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                            placeholder="Contratos, pareceres e processos."
                        />
                    </Field>
                    <div className="flex items-start gap-3">
                        <Switch id="restricted" checked={form.data.restricted} onCheckedChange={(on) => form.setData('restricted', on)} />
                        <div className="grid gap-1">
                            <Label htmlFor="restricted">Acesso restrito</Label>
                            <p className="text-xs text-muted-foreground">Só os departamentos escolhidos, os proprietários e os administradores.</p>
                        </div>
                    </div>
                    {form.data.restricted && (
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
                    )}
                    <div className="flex items-center justify-between gap-2 border-t pt-4">
                        <Button type="button" variant="ghost" onClick={reset}>
                            {editing ? 'Cancelar' : 'Limpar'}
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {editing ? 'Guardar' : 'Criar domínio'}
                        </Button>
                    </div>
                </form>
            </Section>
        </AppLayout>
    );
}
