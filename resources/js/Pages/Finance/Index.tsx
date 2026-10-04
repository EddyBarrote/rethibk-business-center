import { Head, Link, router, useForm } from '@inertiajs/react';
import { Bot, Check, Landmark, Upload, X } from 'lucide-react';
import { type FormEvent } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';
import { date, mzn } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Statement {
    id: number;
    account_name: string;
    bank: string | null;
    source: 'upload' | 'email';
    period_start: string | null;
    period_end: string | null;
    closing_balance: number | null;
    transaction_count: number;
    original_name: string | null;
    created_at: string;
}

interface Transaction {
    id: number;
    date: string;
    description: string;
    reference: string | null;
    amount: number;
    status: 'unmatched' | 'suggested' | 'reconciled' | 'ignored';
    status_label: string;
    match_type: string | null;
    match_ref: string | null;
    match_note: string | null;
    account: string | null;
    run_id: number | null;
}

interface Props {
    statements: Statement[];
    transactions: Paginated<Transaction>;
    totals: { unmatched: number; suggested: number; reconciled: number };
    filter: string;
}

const filters = [
    { value: 'open', label: 'Por reconciliar' },
    { value: 'reconciled', label: 'Reconciliados' },
    { value: 'ignored', label: 'Ignorados' },
    { value: 'all', label: 'Todos' },
];

export default function FinanceIndex({ statements, transactions, totals, filter }: Props) {
    const form = useForm<{ account_name: string; bank: string; file: File | null }>({ account_name: statements[0]?.account_name ?? '', bank: statements[0]?.bank ?? '', file: null });
    const act = (t: Transaction, action: 'confirm' | 'ignore' | 'reset') => router.post(`/finance/transactions/${t.id}`, { action }, { preserveScroll: true });

    const upload = (event: FormEvent) => {
        event.preventDefault();
        form.post('/finance/statements', { forceFormData: true, onSuccess: () => form.setData('file', null) });
    };

    return (
        <AppLayout>
            <Head title="Finanças" />
            <PageHeader
                title="Finanças · reconciliação bancária"
                description="Extractos carregados aqui ou recebidos por email. O agente de finanças propõe a correspondência de cada movimento; uma pessoa confirma."
                actions={
                    <Button variant="outline" onClick={() => router.post('/finance/ask')}>
                        <Bot />
                        Pedir propostas ao agente
                    </Button>
                }
            />

            <div className="grid gap-4 sm:grid-cols-3">
                {[
                    ['Por reconciliar', totals.unmatched],
                    ['Propostas do agente', totals.suggested],
                    ['Reconciliados', totals.reconciled],
                ].map(([label, value]) => (
                    <Card key={label as string}>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">{label}</p>
                            <p className="text-2xl font-semibold tabular-nums">{value}</p>
                        </CardContent>
                    </Card>
                ))}
            </div>

            <div className="grid gap-6 lg:grid-cols-[1fr_2fr]">
                <Card>
                    <form onSubmit={upload}>
                        <CardHeader>
                            <CardTitle>Carregar extracto</CardTitle>
                            <CardDescription>CSV exportado do banco online. Para PDF ou Excel, envie-o para a caixa do agente de finanças.</CardDescription>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-3">
                            <Field id="account_name" label="Conta" error={form.errors.account_name}>
                                <Input id="account_name" placeholder="BCI conta à ordem MZN" value={form.data.account_name} onChange={(e) => form.setData('account_name', e.target.value)} />
                            </Field>
                            <Field id="bank" label="Banco" error={form.errors.bank}>
                                <Input id="bank" value={form.data.bank} onChange={(e) => form.setData('bank', e.target.value)} />
                            </Field>
                            <Field id="file" label="Ficheiro CSV" error={form.errors.file}>
                                <Input id="file" type="file" accept=".csv,.txt" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                            </Field>
                        </CardContent>
                        <CardFooter className="mt-4 justify-end">
                            <Button type="submit" disabled={form.processing || !form.data.file}>
                                <Upload />
                                Importar
                            </Button>
                        </CardFooter>
                    </form>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Extractos recentes</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2">
                        {statements.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Ainda nenhum extracto.</p>
                        ) : (
                            <ul className="grid gap-2 text-sm">
                                {statements.map((s) => (
                                    <li key={s.id} className="flex flex-wrap justify-between gap-2">
                                        <span>
                                            {s.account_name} · {date(s.period_start)} a {date(s.period_end)} · {s.transaction_count} movimento(s)
                                        </span>
                                        <span className="text-muted-foreground">{s.source === 'email' ? 'por email' : 'carregado'} · saldo {mzn(s.closing_balance)}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="flex flex-wrap gap-2">
                {filters.map((f) => (
                    <Button key={f.value} size="sm" variant={filter === f.value ? 'default' : 'outline'} onClick={() => router.get('/finance', { status: f.value }, { preserveState: true })}>
                        {f.label}
                    </Button>
                ))}
            </div>

            {transactions.data.length === 0 ? (
                <EmptyState icon={Landmark} title="Nada por reconciliar" description="Os movimentos dos extractos aparecem aqui com a proposta do agente." />
            ) : (
                <Card className="py-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Data</TableHead>
                                <TableHead>Descrição</TableHead>
                                <TableHead className="text-right">Montante</TableHead>
                                <TableHead>Correspondência</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {transactions.data.map((t) => (
                                <TableRow key={t.id}>
                                    <TableCell className="whitespace-nowrap">{date(t.date)}</TableCell>
                                    <TableCell className="max-w-xs">
                                        <span className="block truncate">{t.description}</span>
                                        {t.reference && <span className="block text-xs text-muted-foreground">{t.reference}</span>}
                                    </TableCell>
                                    <TableCell className={cn('text-right whitespace-nowrap tabular-nums', t.amount < 0 ? 'text-red-600' : 'text-emerald-700')}>{mzn(t.amount)}</TableCell>
                                    <TableCell className="max-w-xs text-sm">
                                        {t.match_type ? (
                                            <>
                                                <Badge variant={t.status === 'reconciled' ? 'default' : 'secondary'}>
                                                    {t.match_type}
                                                    {t.match_ref && ` · ${t.match_ref}`}
                                                </Badge>
                                                {t.match_note && <span className="block text-xs text-muted-foreground">{t.match_note}</span>}
                                                {t.run_id && (
                                                    <Link href={`/runs/${t.run_id}`} className="text-xs text-primary hover:underline">
                                                        proposta do agente
                                                    </Link>
                                                )}
                                            </>
                                        ) : (
                                            <span className="text-muted-foreground">{t.status_label}</span>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap">
                                        {t.status === 'suggested' && (
                                            <Button size="sm" onClick={() => act(t, 'confirm')}>
                                                <Check />
                                                Confirmar
                                            </Button>
                                        )}
                                        {(t.status === 'unmatched' || t.status === 'suggested') && (
                                            <Button size="sm" variant="ghost" onClick={() => act(t, 'ignore')} title="Ignorar">
                                                <X />
                                            </Button>
                                        )}
                                        {(t.status === 'reconciled' || t.status === 'ignored') && (
                                            <Button size="sm" variant="ghost" onClick={() => act(t, 'reset')}>
                                                Reabrir
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
            <Pagination page={transactions} />
        </AppLayout>
    );
}
