import { Head, useForm } from '@inertiajs/react';
import { FileSignature, FileText, Mail, Sparkles } from 'lucide-react';
import { type FormEvent } from 'react';

import { EntityRow, ListPanel, Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import AppLayout from '@/Layouts/AppLayout';
import { ago, date, dateTime, mzn } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = Record<string, unknown>;

interface Sheet {
    account: Row & { name?: string; nuit?: string; sector?: string; city?: string; email?: string; phone?: string; payment_terms_days?: number };
    contacts: (Row & { name?: string; role?: string; email?: string; phone?: string })[];
    balance_due: number | null;
    projects: (Row & { id?: string; name?: string; status?: string })[] | null;
    receivables: (Row & { id?: string; number?: string; outstanding?: number; days_overdue?: number; due_date?: string })[] | null;
    leads: (Row & { id?: string; title?: string; status?: string; estimated_value?: number })[] | null;
    contracts: { id: number; title: string; value: number | null; ends_at: string | null; status: string; sla_response_hours: number | null }[];
    recent_emails: {
        id: number;
        subject: string | null;
        from: string | null;
        category: string | null;
        summary: string | null;
        received_at: string | null;
        link: string;
    }[];
}

const s = (v: unknown) => (v === null || v === undefined ? '' : String(v));

// Estados do ERP (projectos e leads) em português.
const ERP_STATUS: Record<string, string> = {
    planned: 'planeado',
    in_progress: 'em curso',
    on_hold: 'suspenso',
    completed: 'concluído',
    cancelled: 'cancelado',
    new: 'nova',
    contacted: 'contactada',
    qualified: 'qualificada',
    proposal: 'proposta',
    won: 'ganha',
    lost: 'perdida',
};
const status = (v: unknown) => ERP_STATUS[s(v)] ?? s(v);

const erpTone = (v: unknown): Tone =>
    (({
        planned: 'idle',
        in_progress: 'running',
        on_hold: 'warning',
        completed: 'success',
        cancelled: 'idle',
        new: 'idle',
        contacted: 'running',
        qualified: 'running',
        proposal: 'warning',
        won: 'success',
        lost: 'danger',
    })[s(v)] as Tone) ?? 'idle';

const CONTRACT_STATUS: Record<string, { label: string; tone: Tone }> = {
    active: { label: 'Activo', tone: 'success' },
    renewing: { label: 'Em renovação', tone: 'warning' },
    ended: { label: 'Terminado', tone: 'idle' },
    cancelled: { label: 'Cancelado', tone: 'idle' },
};
const contract = (v: string) => CONTRACT_STATUS[v] ?? { label: v, tone: 'idle' as Tone };

export default function ClientShow({
    sheet,
    accountId,
    briefs,
}: {
    sheet: Sheet;
    accountId: string;
    briefs: { id: number; title: string; created_at: string }[];
}) {
    const form = useForm({ meeting: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/clients/${accountId}/brief`);
    };

    const name = s(sheet.account.name);
    const receivables = sheet.receivables ?? [];
    const projects = sheet.projects ?? [];
    const leads = sheet.leads ?? [];

    return (
        <AppLayout wide breadcrumbs={[{ label: 'Clientes', href: '/clients' }, { label: name }]}>
            <Head title={name} />
            <PageHeader title={name} description={[sheet.account.sector, sheet.account.city].filter(Boolean).join(' · ') || undefined} />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-8">
                    <form onSubmit={submit} className="rounded-xl border bg-card p-5">
                        <h2 className="mb-3 text-sm font-semibold">Briefing de reunião</h2>
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                            <Field id="meeting" label="Reunião" error={form.errors.meeting} className="flex-1">
                                <Input
                                    id="meeting"
                                    placeholder="Ex.: 5 de Outubro, revisão do contrato de manutenção"
                                    value={form.data.meeting}
                                    onChange={(e) => form.setData('meeting', e.target.value)}
                                />
                            </Field>
                            <Button type="submit" disabled={form.processing}>
                                <Sparkles />
                                Preparar briefing de reunião
                            </Button>
                        </div>
                    </form>

                    <Section
                        title="Facturas por receber"
                        action={<span className="font-mono text-sm font-medium tabular-nums">{mzn(sheet.balance_due)}</span>}
                    >
                        {receivables.length === 0 ? (
                            <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">Sem facturas por receber.</p>
                        ) : (
                            <ListPanel>
                                {receivables.map((r, i) => {
                                    const overdue = Number(r.days_overdue ?? 0);

                                    return (
                                        <EntityRow
                                            key={i}
                                            title={<span className="font-mono">{s(r.number ?? r.id)}</span>}
                                            subtitle={r.due_date ? `Vence ${date(s(r.due_date))}` : undefined}
                                            meta={
                                                <span className="font-mono text-sm text-foreground tabular-nums">
                                                    {mzn(Number(r.outstanding ?? 0))}
                                                </span>
                                            }
                                            trailing={
                                                overdue > 0 ? (
                                                    <StatusBadge tone="danger" className="tabular-nums">
                                                        {overdue} dias
                                                    </StatusBadge>
                                                ) : (
                                                    <StatusBadge tone="idle">no prazo</StatusBadge>
                                                )
                                            }
                                        />
                                    );
                                })}
                            </ListPanel>
                        )}
                    </Section>

                    {(projects.length > 0 || leads.length > 0) && (
                        <Section title="Projectos e oportunidades">
                            <ListPanel>
                                {projects.map((p, i) => (
                                    <EntityRow
                                        key={`p${i}`}
                                        title={s(p.name)}
                                        subtitle="Projecto"
                                        meta={p.id ? <span className="font-mono">{s(p.id)}</span> : undefined}
                                        trailing={<StatusBadge tone={erpTone(p.status)}>{status(p.status)}</StatusBadge>}
                                    />
                                ))}
                                {leads.map((l, i) => (
                                    <EntityRow
                                        key={`l${i}`}
                                        title={s(l.title)}
                                        subtitle="Lead"
                                        meta={
                                            l.estimated_value ? (
                                                <span className="font-mono tabular-nums">{mzn(Number(l.estimated_value))}</span>
                                            ) : undefined
                                        }
                                        trailing={<StatusBadge tone={erpTone(l.status)}>{status(l.status)}</StatusBadge>}
                                    />
                                ))}
                            </ListPanel>
                        </Section>
                    )}

                    <Section title="Emails recentes">
                        {sheet.recent_emails.length === 0 ? (
                            <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">
                                Sem emails deste cliente nas caixas dos agentes.
                            </p>
                        ) : (
                            <ListPanel>
                                {sheet.recent_emails.map((e) => (
                                    <EntityRow
                                        key={e.id}
                                        href={e.link}
                                        leading={<Mail className="size-4 text-muted-foreground" />}
                                        title={e.subject ?? '(sem assunto)'}
                                        subtitle={e.summary ?? e.from}
                                        meta={
                                            <span className="tabular-nums" title={dateTime(e.received_at)}>
                                                {ago(e.received_at)}
                                            </span>
                                        }
                                    />
                                ))}
                            </ListPanel>
                        )}
                    </Section>

                    <Section title="Contratos e briefings">
                        {sheet.contracts.length === 0 && briefs.length === 0 ? (
                            <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">Sem contratos registados.</p>
                        ) : (
                            <ListPanel>
                                {sheet.contracts.map((c) => (
                                    <EntityRow
                                        key={c.id}
                                        href={`/contracts/${c.id}`}
                                        leading={<FileSignature className="size-4 text-muted-foreground" />}
                                        title={c.title}
                                        subtitle={[`fim ${date(c.ends_at)}`, c.sla_response_hours && `SLA ${c.sla_response_hours} h`]
                                            .filter(Boolean)
                                            .join(' · ')}
                                        meta={<span className="font-mono tabular-nums">{mzn(c.value)}</span>}
                                        trailing={<StatusBadge tone={contract(c.status).tone}>{contract(c.status).label}</StatusBadge>}
                                    />
                                ))}
                                {briefs.map((b) => (
                                    <EntityRow
                                        key={`b${b.id}`}
                                        href={`/reports/${b.id}`}
                                        leading={<FileText className="size-4 text-primary" />}
                                        title={b.title}
                                        subtitle="Briefing de reunião"
                                        meta={
                                            <span className="tabular-nums" title={dateTime(b.created_at)}>
                                                {ago(b.created_at)}
                                            </span>
                                        }
                                    />
                                ))}
                            </ListPanel>
                        )}
                    </Section>
                </div>

                <div className="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start">
                    <Properties title="Cliente">
                        <Property label="ID">
                            <span className="font-mono text-xs">{accountId}</span>
                        </Property>
                        <Property label="NUIT">{sheet.account.nuit && <span className="font-mono text-xs">{s(sheet.account.nuit)}</span>}</Property>
                        <Property label="Sector">{sheet.account.sector && s(sheet.account.sector)}</Property>
                        <Property label="Cidade">{sheet.account.city && s(sheet.account.city)}</Property>
                        <Property label="Email">{sheet.account.email && s(sheet.account.email)}</Property>
                        <Property label="Telefone">{sheet.account.phone && <span className="tabular-nums">{s(sheet.account.phone)}</span>}</Property>
                        <Property label="Pagamento">
                            {sheet.account.payment_terms_days !== undefined && sheet.account.payment_terms_days !== null && (
                                <span className="tabular-nums">{s(sheet.account.payment_terms_days)} dias</span>
                            )}
                        </Property>
                        <Property label="Em dívida">
                            <span className={cn('font-mono tabular-nums', (sheet.balance_due ?? 0) > 0 && 'font-medium')}>
                                {mzn(sheet.balance_due)}
                            </span>
                        </Property>
                    </Properties>

                    <Section title="Contactos">
                        {sheet.contacts.length === 0 ? (
                            <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">Sem contactos no ERP.</p>
                        ) : (
                            <ListPanel>
                                {sheet.contacts.map((c, i) => (
                                    <EntityRow
                                        key={i}
                                        leading={<Monogram name={s(c.name)} />}
                                        title={s(c.name)}
                                        subtitle={[c.role, c.email, c.phone].filter(Boolean).map(s).join(' · ')}
                                    />
                                ))}
                            </ListPanel>
                        )}
                    </Section>
                </div>
            </div>
        </AppLayout>
    );
}
