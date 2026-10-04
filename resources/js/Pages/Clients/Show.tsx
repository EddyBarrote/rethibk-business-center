import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Sparkles } from 'lucide-react';
import { type FormEvent } from 'react';

import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import AppLayout from '@/Layouts/AppLayout';
import { date, dateTime, mzn } from '@/lib/format';

type Row = Record<string, unknown>;

interface Sheet {
    account: Row & { name?: string; nuit?: string; sector?: string; city?: string; email?: string; phone?: string; payment_terms_days?: number };
    contacts: (Row & { name?: string; role?: string; email?: string; phone?: string })[];
    balance_due: number | null;
    projects: (Row & { id?: string; name?: string; status?: string })[] | null;
    receivables: (Row & { id?: string; number?: string; outstanding?: number; days_overdue?: number; due_date?: string })[] | null;
    leads: (Row & { id?: string; title?: string; status?: string; estimated_value?: number })[] | null;
    contracts: { id: number; title: string; value: number | null; ends_at: string | null; status: string; sla_response_hours: number | null }[];
    recent_emails: { id: number; subject: string | null; from: string | null; category: string | null; summary: string | null; received_at: string | null; link: string }[];
}

const s = (v: unknown) => (v === null || v === undefined ? '' : String(v));

export default function ClientShow({ sheet, accountId, briefs }: { sheet: Sheet; accountId: string; briefs: { id: number; title: string; created_at: string }[] }) {
    const form = useForm({ meeting: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/clients/${accountId}/brief`);
    };

    return (
        <AppLayout>
            <Head title={s(sheet.account.name)} />
            <div>
                <Link href="/clients" className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    Clientes
                </Link>
            </div>
            <PageHeader title={s(sheet.account.name)} description={[accountId, sheet.account.sector, sheet.account.city, sheet.account.nuit && `NUIT ${sheet.account.nuit}`].filter(Boolean).join(' · ')} />

            <Card>
                <form onSubmit={submit}>
                    <CardContent className="flex flex-col gap-2 sm:flex-row sm:items-center">
                        <Input placeholder="Reunião (ex.: 5 de Outubro, revisão do contrato de manutenção)" value={form.data.meeting} onChange={(e) => form.setData('meeting', e.target.value)} />
                        <Button type="submit" disabled={form.processing}>
                            <Sparkles />
                            Preparar briefing de reunião
                        </Button>
                    </CardContent>
                </form>
            </Card>

            <div className="grid gap-6 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardTitle>Contactos</CardTitle>
                        <CardDescription>
                            {s(sheet.account.email)} · {s(sheet.account.phone)} · pagamento a {s(sheet.account.payment_terms_days)} dias
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-2 text-sm">
                        {sheet.contacts.map((c, i) => (
                            <p key={i}>
                                <span className="font-medium">{s(c.name)}</span> · {s(c.role)}
                                <span className="block text-xs text-muted-foreground">
                                    {s(c.email)} {s(c.phone)}
                                </span>
                            </p>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Em dívida: {mzn(sheet.balance_due)}</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-1 text-sm">
                        {(sheet.receivables ?? []).length === 0 && <p className="text-muted-foreground">Sem facturas por receber.</p>}
                        {(sheet.receivables ?? []).map((r, i) => (
                            <p key={i} className="flex justify-between gap-2">
                                <span>{s(r.number ?? r.id)}</span>
                                <span className="tabular-nums">
                                    {mzn(Number(r.outstanding ?? 0))}
                                    {Number(r.days_overdue ?? 0) > 0 && <Badge variant="destructive" className="ml-2">{s(r.days_overdue)} dias</Badge>}
                                </span>
                            </p>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Projectos e oportunidades</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-1 text-sm">
                        {(sheet.projects ?? []).map((p, i) => (
                            <p key={i}>
                                {s(p.name)} <Badge variant="outline">{s(p.status)}</Badge>
                            </p>
                        ))}
                        {(sheet.leads ?? []).map((l, i) => (
                            <p key={`l${i}`} className="text-muted-foreground">
                                Lead: {s(l.title)} · {s(l.status)}
                                {l.estimated_value ? ` · ${mzn(Number(l.estimated_value))}` : ''}
                            </p>
                        ))}
                    </CardContent>
                </Card>
            </div>

            <div className="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Emails recentes</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-2 text-sm">
                        {sheet.recent_emails.length === 0 && <p className="text-muted-foreground">Sem emails deste cliente nas caixas dos agentes.</p>}
                        {sheet.recent_emails.map((e) => (
                            <Link key={e.id} href={e.link} className="hover:underline">
                                <span className="block">{e.subject}</span>
                                <span className="block text-xs text-muted-foreground">
                                    {dateTime(e.received_at)} · {e.summary}
                                </span>
                            </Link>
                        ))}
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Contratos e briefings</CardTitle>
                    </CardHeader>
                    <CardContent className="mt-2 grid gap-2 text-sm">
                        {sheet.contracts.map((c) => (
                            <Link key={c.id} href={`/contracts/${c.id}`} className="hover:underline">
                                {c.title} · {mzn(c.value)} · fim {date(c.ends_at)}
                                {c.sla_response_hours && ` · SLA ${c.sla_response_hours} h`}
                            </Link>
                        ))}
                        {briefs.map((b) => (
                            <Link key={b.id} href={`/reports/${b.id}`} className="text-primary hover:underline">
                                {b.title} · {dateTime(b.created_at)}
                            </Link>
                        ))}
                        {sheet.contracts.length === 0 && briefs.length === 0 && <p className="text-muted-foreground">Sem contratos registados.</p>}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
