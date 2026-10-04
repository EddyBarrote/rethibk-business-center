import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { date, dateTime } from '@/lib/format';
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

    const setItem = (index: number, changes: Partial<Item>) => form.setData('items', form.data.items.map((item, i) => (i === index ? { ...item, ...changes } : item)));

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
                <Card>
                    <form onSubmit={submit}>
                        <CardHeader>
                            <CardTitle>Nova requisição</CardTitle>
                            <CardDescription>O que é preciso, quanto e até quando.</CardDescription>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-4">
                            <Field id="title" label="Título" error={form.errors.title}>
                                <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Varão de aço para a obra da ala norte" />
                            </Field>
                            <div className="grid gap-2">
                                <p className="text-sm font-medium">Artigos</p>
                                {form.data.items.map((item, index) => (
                                    <div key={index} className="grid grid-cols-[1fr_6rem_6rem_auto] gap-2">
                                        <Input placeholder="Descrição" value={item.description} onChange={(e) => setItem(index, { description: e.target.value })} />
                                        <Input type="number" step="any" min="0" placeholder="Qtd." value={item.quantity} onChange={(e) => setItem(index, { quantity: e.target.value })} />
                                        <Input placeholder="Unid." value={item.unit} onChange={(e) => setItem(index, { unit: e.target.value })} />
                                        <Button type="button" variant="ghost" size="icon" disabled={form.data.items.length === 1} onClick={() => form.setData('items', form.data.items.filter((_, i) => i !== index))}>
                                            <Trash2 />
                                        </Button>
                                    </div>
                                ))}
                                {form.errors.items && <p className="text-sm text-destructive">{form.errors.items}</p>}
                                <Button type="button" variant="outline" size="sm" className="w-fit" onClick={() => form.setData('items', [...form.data.items, { description: '', quantity: '1', unit: '' }])}>
                                    <Plus />
                                    Artigo
                                </Button>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field id="needed_by" label="Necessário até" error={form.errors.needed_by}>
                                    <Input id="needed_by" type="date" value={form.data.needed_by} onChange={(e) => form.setData('needed_by', e.target.value)} />
                                </Field>
                                <Field id="budget" label="Orçamento (MZN)" error={form.errors.budget}>
                                    <Input id="budget" type="number" min="0" value={form.data.budget} onChange={(e) => form.setData('budget', e.target.value)} />
                                </Field>
                                <Field id="project_ref" label="Projecto (ERP)" error={form.errors.project_ref}>
                                    <Input id="project_ref" placeholder="PRJ-0001" value={form.data.project_ref} onChange={(e) => form.setData('project_ref', e.target.value)} />
                                </Field>
                            </div>
                            <Field id="description" label="Notas" error={form.errors.description}>
                                <Textarea id="description" rows={3} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </Field>
                        </CardContent>
                        <CardFooter className="mt-4 justify-end">
                            <Button type="submit" disabled={form.processing}>
                                Submeter
                            </Button>
                        </CardFooter>
                    </form>
                </Card>
            )}

            {requests.data.length === 0 ? (
                <EmptyState icon={ShoppingCart} title="Sem requisições" description="As requisições submetidas aparecem aqui com o estado do processo de compra." />
            ) : (
                <Card className="divide-y py-0">
                    {requests.data.map((r) => (
                        <Link key={r.id} href={`/procurement/${r.id}`} className="flex flex-wrap items-center gap-3 px-4 py-3 hover:bg-muted/50">
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-sm font-medium">{r.title}</span>
                                <span className="block text-xs text-muted-foreground">
                                    {r.requested_by} · {r.items_count} artigo(s) · {dateTime(r.created_at)}
                                    {r.needed_by && ` · até ${date(r.needed_by)}`}
                                </span>
                            </span>
                            {r.erp_rfq_id && <Badge variant="outline">{r.erp_rfq_id}</Badge>}
                            {r.erp_po_id && <Badge variant="outline">{r.erp_po_id}</Badge>}
                            <Badge variant="secondary">{r.status_label}</Badge>
                        </Link>
                    ))}
                </Card>
            )}
            <Pagination page={requests} />

            {suppliers.length > 0 && (
                <Card>
                    <CardHeader>
                        <CardTitle>Avaliação de fornecedores</CardTitle>
                        <CardDescription>Média das avaliações depois de cada entrega (1 a 5).</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-2">
                        <ul className="grid gap-1 text-sm">
                            {suppliers.map((s) => (
                                <li key={s.supplier_id} className="flex justify-between gap-2">
                                    <span>{s.supplier_name}</span>
                                    <span className="text-muted-foreground tabular-nums">
                                        {s.overall.toFixed(1)} · pontualidade {s.on_time.toFixed(1)} · qualidade {s.quality.toFixed(1)} · preço {s.price.toFixed(1)} ({s.ratings})
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            )}
        </AppLayout>
    );
}

