import { Head, useForm } from '@inertiajs/react';
import { Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EntityRow, ListPanel, Monogram, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { ago, date, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export interface PurchaseRequestSummary {
    id: number;
    title: string;
    status: string;
    status_label: string;
    needed_by: string | null;
    budget: number | null;
    project_ref: string | null;
    erp_rfq_id: string | null;
    erp_po_id: string | null;
    requested_by: string | null;
    department: string | null;
    items_count: number;
    created_at: string;
}

interface Item {
    description: string;
    quantity: string;
    unit: string;
}

interface SupplierScore {
    supplier_id: string;
    supplier_name: string;
    ratings: number;
    on_time: number;
    quality: number;
    price: number;
    overall: number;
}

/** Purchase-request states on the shared status scale: the agent is working, a person must act, or it is settled. */
export const purchaseTone = (status: string): Tone =>
    (({
        submitted: 'idle',
        rfq: 'running',
        quoting: 'running',
        compared: 'running',
        po_draft: 'warning',
        ordered: 'success',
        received: 'success',
        cancelled: 'idle',
    })[status] as Tone) ?? 'idle';

const head = 'h-9 px-4 text-xs font-medium tracking-wide text-muted-foreground uppercase';

const score = (value: number) => (
    <span className={cn('font-mono tabular-nums', value < 3 && 'text-status-danger', value >= 4 && 'text-status-success')}>{value.toFixed(1)}</span>
);

export default function ProcurementIndex({ requests, suppliers }: { requests: Paginated<PurchaseRequestSummary>; suppliers: SupplierScore[] }) {
    const [adding, setAdding] = useState(false);
    const form = useForm<{ title: string; description: string; items: Item[]; needed_by: string; budget: string; project_ref: string }>({
        title: '',
        description: '',
        items: [{ description: '', quantity: '1', unit: '' }],
        needed_by: '',
        budget: '',
        project_ref: '',
    });

    const setItem = (index: number, changes: Partial<Item>) =>
        form.setData(
            'items',
            form.data.items.map((item, i) => (i === index ? { ...item, ...changes } : item)),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/procurement');
    };

    return (
        <AppLayout>
            <Head title="Compras" />
            <PageHeader
                title="Compras"
                description="Faça uma requisição: o agente de compras pede cotações, prepara o mapa comparativo e o rascunho da nota de encomenda para aprovação."
                actions={
                    <Button onClick={() => setAdding(!adding)} variant={adding ? 'outline' : 'default'}>
                        <Plus />
                        Nova requisição
                    </Button>
                }
            />

            {adding && (
                <form onSubmit={submit} className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                    <div>
                        <h2 className="text-sm font-semibold">Nova requisição</h2>
                        <p className="text-xs text-muted-foreground">O que é preciso, quanto e até quando.</p>
                    </div>
                    <Field id="title" label="Título" error={form.errors.title}>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="Varão de aço para a obra da ala norte"
                        />
                    </Field>
                    <div className="grid gap-2">
                        <p className="text-sm font-medium">Artigos</p>
                        <div className="grid gap-2 rounded-lg border bg-muted/30 p-3">
                            <div className="hidden grid-cols-[1fr_6rem_6rem_2.25rem] gap-2 text-xs font-medium tracking-wide text-muted-foreground uppercase sm:grid">
                                <span>Descrição</span>
                                <span>Qtd.</span>
                                <span>Unid.</span>
                                <span />
                            </div>
                            {form.data.items.map((item, index) => (
                                <div key={index} className="grid grid-cols-[1fr_5rem_5rem_2.25rem] gap-2 sm:grid-cols-[1fr_6rem_6rem_2.25rem]">
                                    <Input
                                        placeholder="Descrição"
                                        value={item.description}
                                        onChange={(e) => setItem(index, { description: e.target.value })}
                                    />
                                    <Input
                                        type="number"
                                        step="any"
                                        min="0"
                                        placeholder="Qtd."
                                        className="font-mono tabular-nums"
                                        value={item.quantity}
                                        onChange={(e) => setItem(index, { quantity: e.target.value })}
                                    />
                                    <Input placeholder="Unid." value={item.unit} onChange={(e) => setItem(index, { unit: e.target.value })} />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remover artigo"
                                        disabled={form.data.items.length === 1}
                                        onClick={() =>
                                            form.setData(
                                                'items',
                                                form.data.items.filter((_, i) => i !== index),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            ))}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="w-fit"
                                onClick={() => form.setData('items', [...form.data.items, { description: '', quantity: '1', unit: '' }])}
                            >
                                <Plus />
                                Artigo
                            </Button>
                        </div>
                        <InputError message={form.errors.items} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-3">
                        <Field id="needed_by" label="Necessário até" error={form.errors.needed_by}>
                            <Input
                                id="needed_by"
                                type="date"
                                value={form.data.needed_by}
                                onChange={(e) => form.setData('needed_by', e.target.value)}
                            />
                        </Field>
                        <Field id="budget" label="Orçamento (MZN)" error={form.errors.budget}>
                            <Input
                                id="budget"
                                type="number"
                                min="0"
                                className="font-mono tabular-nums"
                                value={form.data.budget}
                                onChange={(e) => form.setData('budget', e.target.value)}
                            />
                        </Field>
                        <Field id="project_ref" label="Projecto (ERP)" error={form.errors.project_ref}>
                            <Input
                                id="project_ref"
                                placeholder="PRJ-0001"
                                className="font-mono"
                                value={form.data.project_ref}
                                onChange={(e) => form.setData('project_ref', e.target.value)}
                            />
                        </Field>
                    </div>
                    <Field id="description" label="Notas" error={form.errors.description}>
                        <Textarea
                            id="description"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                    </Field>
                    <div className="flex items-center justify-between gap-2 border-t pt-4">
                        <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Enviar requisição
                        </Button>
                    </div>
                </form>
            )}

            <Section title="Requisições">
                {requests.data.length === 0 ? (
                    <EmptyState
                        icon={ShoppingCart}
                        title="Sem requisições"
                        description="Comece por uma nova requisição: diga o que é preciso e o agente de compras trata das cotações."
                    />
                ) : (
                    <ListPanel>
                        {requests.data.map((r) => (
                            <EntityRow
                                key={r.id}
                                href={`/procurement/${r.id}`}
                                leading={<Monogram name={r.requested_by ?? r.title} />}
                                title={r.title}
                                subtitle={
                                    <>
                                        {[r.requested_by, r.department, `${r.items_count} artigo(s)`].filter(Boolean).join(' · ')}
                                        {r.needed_by && ` · até ${date(r.needed_by)}`}
                                    </>
                                }
                                meta={
                                    <>
                                        {r.erp_rfq_id && <span className="font-mono">{r.erp_rfq_id}</span>}
                                        {r.erp_po_id && <span className="font-mono">{r.erp_po_id}</span>}
                                        <span title={dateTime(r.created_at)}>{ago(r.created_at)}</span>
                                    </>
                                }
                                trailing={<StatusBadge tone={purchaseTone(r.status)}>{r.status_label}</StatusBadge>}
                            />
                        ))}
                    </ListPanel>
                )}
                <Pagination page={requests} />
            </Section>

            {suppliers.length > 0 && (
                <Section
                    title="Avaliação de fornecedores"
                    action={<span className="text-xs text-muted-foreground">Média depois de cada entrega (1 a 5)</span>}
                >
                    <div className="overflow-hidden rounded-xl border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className={head}>Fornecedor</TableHead>
                                    <TableHead className={cn(head, 'text-right')}>Global</TableHead>
                                    <TableHead className={cn(head, 'text-right')}>Pontualidade</TableHead>
                                    <TableHead className={cn(head, 'text-right')}>Qualidade</TableHead>
                                    <TableHead className={cn(head, 'text-right')}>Preço</TableHead>
                                    <TableHead className={cn(head, 'text-right')}>Avaliações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {suppliers.map((s) => (
                                    <TableRow key={s.supplier_id}>
                                        <TableCell className="px-4 py-2">
                                            <span className="font-medium">{s.supplier_name}</span>
                                            <span className="ml-2 font-mono text-[11px] text-muted-foreground">{s.supplier_id}</span>
                                        </TableCell>
                                        <TableCell className="px-4 py-2 text-right font-semibold">{score(s.overall)}</TableCell>
                                        <TableCell className="px-4 py-2 text-right">{score(s.on_time)}</TableCell>
                                        <TableCell className="px-4 py-2 text-right">{score(s.quality)}</TableCell>
                                        <TableCell className="px-4 py-2 text-right">{score(s.price)}</TableCell>
                                        <TableCell className="px-4 py-2 text-right font-mono text-muted-foreground tabular-nums">
                                            {s.ratings}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </Section>
            )}
        </AppLayout>
    );
}
