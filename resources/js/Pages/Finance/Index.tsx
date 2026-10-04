import { Head, Link, router, useForm } from '@inertiajs/react';
import { Bot, Check, CheckCheck, FileSpreadsheet, Landmark, Mail, Sparkles, Upload, X } from 'lucide-react';
import { type FormEvent } from 'react';

import { EntityRow, ListPanel, MetricCard, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AppLayout from '@/Layouts/AppLayout';
import { ago, date, dateTime, mzn } from '@/lib/format';
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

const transactionTone = (status: Transaction['status']): Tone =>
    ({ unmatched: 'idle', suggested: 'warning', reconciled: 'success', ignored: 'idle' })[status] as Tone;

const head = 'h-9 px-4 text-xs font-medium tracking-wide text-muted-foreground uppercase';

export default function FinanceIndex({ statements, transactions, totals, filter }: Props) {
    const form = useForm<{ account_name: string; bank: string; file: File | null }>({
        account_name: statements[0]?.account_name ?? '',
        bank: statements[0]?.bank ?? '',
        file: null,
    });
    const act = (t: Transaction, action: 'confirm' | 'ignore' | 'reset') =>
        router.post(`/finance/transactions/${t.id}`, { action }, { preserveScroll: true });

    const upload = (event: FormEvent) => {
        event.preventDefault();
        form.post('/finance/statements', { forceFormData: true, onSuccess: () => form.setData('file', null) });
    };

    return (
        <AppLayout wide>
            <Head title="Finanças" />
            <PageHeader
                title="Finanças"
                description="Reconciliação bancária. Extractos carregados aqui ou recebidos por email; o agente de finanças propõe a correspondência de cada movimento e uma pessoa confirma."
                actions={
                    <Button variant="outline" onClick={() => router.post('/finance/ask')}>
                        <Bot />
                        Pedir propostas ao agente
                    </Button>
                }
            />

            <div className="grid divide-y divide-border rounded-xl border bg-card sm:grid-cols-3 sm:divide-x sm:divide-y-0 [&>*]:min-w-0">
                <MetricCard
                    icon={Landmark}
                    value={totals.unmatched}
                    label="Por reconciliar"
                    description="Movimentos sem correspondência"
                    tone={totals.unmatched > 0 ? 'warning' : undefined}
                />
                <MetricCard icon={Sparkles} value={totals.suggested} label="Propostas do agente" description="À espera de confirmação humana" />
                <MetricCard icon={CheckCheck} value={totals.reconciled} label="Reconciliados" description="Movimentos confirmados" />
            </div>

            <div className="grid gap-8 lg:grid-cols-[22rem_minmax(0,1fr)]">
                <Section title="Carregar extracto">
                    <form onSubmit={upload} className="flex flex-col gap-4 rounded-xl border bg-card p-5">
                        <p className="text-xs text-muted-foreground">
                            CSV exportado do banco online. Para PDF ou Excel, envie-o para a caixa do agente de finanças.
                        </p>
                        <Field id="account_name" label="Conta" error={form.errors.account_name}>
                            <Input
                                id="account_name"
                                placeholder="BCI conta à ordem MZN"
                                value={form.data.account_name}
                                onChange={(e) => form.setData('account_name', e.target.value)}
                            />
                        </Field>
                        <Field id="bank" label="Banco" error={form.errors.bank}>
                            <Input id="bank" value={form.data.bank} onChange={(e) => form.setData('bank', e.target.value)} />
                        </Field>
                        <Field id="file" label="Ficheiro CSV" error={form.errors.file}>
                            <Input id="file" type="file" accept=".csv,.txt" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                        </Field>
                        <div className="flex justify-end">
                            <Button type="submit" disabled={form.processing || !form.data.file}>
                                <Upload />
                                Importar
                            </Button>
                        </div>
                    </form>
                </Section>

                <Section title="Extractos recentes">
                    {statements.length === 0 ? (
                        <EmptyState
                            icon={FileSpreadsheet}
                            title="Ainda nenhum extracto"
                            description="Carregue o primeiro CSV do banco ao lado, ou envie o extracto por email para a caixa do agente de finanças."
                        />
                    ) : (
                        <ListPanel>
                            {statements.map((s) => (
                                <EntityRow
                                    key={s.id}
                                    leading={
                                        <span className="inline-flex size-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                            {s.source === 'email' ? <Mail className="size-3.5" /> : <FileSpreadsheet className="size-3.5" />}
                                        </span>
                                    }
                                    title={s.account_name}
                                    subtitle={`${s.bank ? `${s.bank} · ` : ''}${date(s.period_start)} a ${date(s.period_end)} · ${s.transaction_count} movimento(s)`}
                                    meta={
                                        <>
                                            <span>{s.source === 'email' ? 'por email' : 'carregado'}</span>
                                            <span title={dateTime(s.created_at)}>{ago(s.created_at)}</span>
                                        </>
                                    }
                                    trailing={
                                        <span className="font-mono text-xs tabular-nums" title="Saldo final">
                                            {mzn(s.closing_balance)}
                                        </span>
                                    }
                                />
                            ))}
                        </ListPanel>
                    )}
                </Section>
            </div>

            <Section
                title="Movimentos"
                action={
                    <div className="inline-flex rounded-lg border bg-card p-0.5">
                        {filters.map((f) => (
                            <button
                                key={f.value}
                                type="button"
                                onClick={() => router.get('/finance', { status: f.value }, { preserveState: true })}
                                className={cn(
                                    'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                                    filter === f.value ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                {f.label}
                            </button>
                        ))}
                    </div>
                }
            >
                {transactions.data.length === 0 ? (
                    <EmptyState
                        icon={Landmark}
                        title="Nada por reconciliar"
                        description="Carregue um extracto: os movimentos aparecem aqui com a proposta de correspondência do agente."
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border bg-card">
                        <Table>
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className={head}>Data</TableHead>
                                    <TableHead className={head}>Descrição</TableHead>
                                    <TableHead className={cn(head, 'text-right')}>Montante</TableHead>
                                    <TableHead className={head}>Correspondência</TableHead>
                                    <TableHead className={head}>Estado</TableHead>
                                    <TableHead className={head} />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {transactions.data.map((t) => (
                                    <TableRow key={t.id}>
                                        <TableCell className="px-4 py-2 font-mono text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                            {date(t.date)}
                                        </TableCell>
                                        <TableCell className="max-w-xs px-4 py-2">
                                            <span className="block truncate">{t.description}</span>
                                            {(t.reference || t.account) && (
                                                <span className="block truncate font-mono text-[11px] text-muted-foreground">
                                                    {[t.reference, t.account].filter(Boolean).join(' · ')}
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'px-4 py-2 text-right font-mono whitespace-nowrap tabular-nums',
                                                t.amount < 0 ? 'text-status-danger' : 'text-status-success',
                                            )}
                                        >
                                            {mzn(t.amount)}
                                        </TableCell>
                                        <TableCell className="max-w-xs px-4 py-2 text-sm">
                                            {t.match_type ? (
                                                <div className="flex min-w-0 flex-col gap-0.5">
                                                    <span className="truncate">
                                                        {t.match_type}
                                                        {t.match_ref && (
                                                            <span className="font-mono text-xs text-muted-foreground"> · {t.match_ref}</span>
                                                        )}
                                                    </span>
                                                    {t.match_note && <span className="truncate text-xs text-muted-foreground">{t.match_note}</span>}
                                                    {t.run_id && (
                                                        <Link href={`/runs/${t.run_id}`} className="w-fit text-xs text-primary hover:underline">
                                                            proposta do agente <span className="font-mono">#{t.run_id}</span>
                                                        </Link>
                                                    )}
                                                </div>
                                            ) : (
                                                <span className="text-muted-foreground">—</span>
                                            )}
                                        </TableCell>
                                        <TableCell className="px-4 py-2">
                                            <StatusBadge tone={transactionTone(t.status)}>{t.status_label}</StatusBadge>
                                        </TableCell>
                                        <TableCell className="px-4 py-2 text-right whitespace-nowrap">
                                            <div className="flex justify-end gap-1">
                                                {t.status === 'suggested' && (
                                                    <Button size="sm" onClick={() => act(t, 'confirm')}>
                                                        <Check />
                                                        Confirmar
                                                    </Button>
                                                )}
                                                {(t.status === 'unmatched' || t.status === 'suggested') && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => act(t, 'ignore')}
                                                        title="Ignorar"
                                                        aria-label="Ignorar"
                                                    >
                                                        <X />
                                                    </Button>
                                                )}
                                                {(t.status === 'reconciled' || t.status === 'ignored') && (
                                                    <Button size="sm" variant="ghost" onClick={() => act(t, 'reset')}>
                                                        Reabrir
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                <Pagination page={transactions} />
            </Section>
        </AppLayout>
    );
}
