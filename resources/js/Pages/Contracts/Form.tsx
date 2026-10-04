import { useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import type { Option } from '@/types';

export interface ContractData {
    id?: number;
    party_type: string;
    party_ref: string | null;
    party_name: string;
    party_domain: string | null;
    title: string;
    reference: string | null;
    value: number | null;
    starts_at: string | null;
    ends_at: string | null;
    notice_days: number;
    auto_renews: boolean;
    sla_response_hours: number | null;
    owner_user_id: number | null;
    status: string;
    notes?: string | null;
}

export interface ContractOptions {
    partyTypes: Option[];
    statuses: Option[];
    users: { id: number; name: string }[];
}

export function ContractForm({ contract, options, onDone }: { contract?: ContractData; options: ContractOptions; onDone?: () => void }) {
    const form = useForm({
        party_type: contract?.party_type ?? 'client',
        party_ref: contract?.party_ref ?? '',
        party_name: contract?.party_name ?? '',
        party_domain: contract?.party_domain ?? '',
        title: contract?.title ?? '',
        reference: contract?.reference ?? '',
        value: contract?.value?.toString() ?? '',
        starts_at: contract?.starts_at ?? '',
        ends_at: contract?.ends_at ?? '',
        notice_days: contract?.notice_days ?? 60,
        auto_renews: contract?.auto_renews ?? false,
        sla_response_hours: contract?.sla_response_hours?.toString() ?? '',
        owner_user_id: contract?.owner_user_id?.toString() ?? '',
        status: contract?.status ?? 'active',
        notes: contract?.notes ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone?.() };
        if (contract?.id) {
            form.put(`/contracts/${contract.id}`, options);
        } else {
            form.post('/contracts', options);
        }
    };

    return (
        <form onSubmit={submit} className="grid gap-4">
            <div className="grid gap-4 sm:grid-cols-3">
                <Field id="party_type" label="Tipo" error={form.errors.party_type}>
                    <NativeSelect id="party_type" value={form.data.party_type} onChange={(e) => form.setData('party_type', e.target.value)}>
                        {options.partyTypes.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="party_name" label="Entidade" error={form.errors.party_name}>
                    <Input id="party_name" value={form.data.party_name} onChange={(e) => form.setData('party_name', e.target.value)} />
                </Field>
                <Field id="party_ref" label="Id no ERP" error={form.errors.party_ref} hint="Ex.: ACC-0002 ou SUP-0001">
                    <Input id="party_ref" value={form.data.party_ref} onChange={(e) => form.setData('party_ref', e.target.value)} />
                </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-3">
                <Field id="title" label="Título" error={form.errors.title} className="sm:col-span-2">
                    <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                </Field>
                <Field id="reference" label="Referência" error={form.errors.reference}>
                    <Input id="reference" value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} />
                </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-4">
                <Field id="value" label="Valor (MZN)" error={form.errors.value}>
                    <Input id="value" type="number" min="0" value={form.data.value} onChange={(e) => form.setData('value', e.target.value)} />
                </Field>
                <Field id="starts_at" label="Início" error={form.errors.starts_at}>
                    <Input id="starts_at" type="date" value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} />
                </Field>
                <Field id="ends_at" label="Fim" error={form.errors.ends_at}>
                    <Input id="ends_at" type="date" value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} />
                </Field>
                <Field id="notice_days" label="Aviso (dias antes)" error={form.errors.notice_days}>
                    <Input id="notice_days" type="number" min="0" value={form.data.notice_days} onChange={(e) => form.setData('notice_days', Number(e.target.value))} />
                </Field>
            </div>
            <div className="grid gap-4 sm:grid-cols-4">
                <Field id="sla_response_hours" label="SLA de resposta (h)" error={form.errors.sla_response_hours} hint="Só clientes">
                    <Input id="sla_response_hours" type="number" min="1" value={form.data.sla_response_hours} onChange={(e) => form.setData('sla_response_hours', e.target.value)} />
                </Field>
                <Field id="party_domain" label="Domínio de email" error={form.errors.party_domain} hint="Ex.: baiaazul.co.mz">
                    <Input id="party_domain" value={form.data.party_domain} onChange={(e) => form.setData('party_domain', e.target.value)} />
                </Field>
                <Field id="owner_user_id" label="Responsável" error={form.errors.owner_user_id}>
                    <NativeSelect id="owner_user_id" value={form.data.owner_user_id} onChange={(e) => form.setData('owner_user_id', e.target.value)}>
                        <option value="">—</option>
                        {options.users.map((u) => (
                            <option key={u.id} value={u.id}>
                                {u.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="status" label="Estado" error={form.errors.status}>
                    <NativeSelect id="status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                        {options.statuses.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
            </div>
            <div className="flex items-center gap-2">
                <Checkbox id="auto_renews" checked={form.data.auto_renews} onCheckedChange={(v) => form.setData('auto_renews', v === true)} />
                <Label htmlFor="auto_renews">Renova automaticamente</Label>
            </div>
            <Field id="notes" label="Notas" error={form.errors.notes}>
                <Textarea id="notes" rows={3} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
            </Field>
            <div className="flex justify-end">
                <Button type="submit" disabled={form.processing}>
                    {contract?.id ? 'Guardar' : 'Registar contrato'}
                </Button>
            </div>
        </form>
    );
}
