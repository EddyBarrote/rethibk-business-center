import { Link, router, useForm } from '@inertiajs/react';
import { Activity, Bot, CircleDollarSign, ExternalLink, Gauge, Plus, Puzzle, Sparkles, Trash2 } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListPanel, MetricCard, Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { agentTone, StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import AdminLayout from '@/Layouts/AdminLayout';
import { ago, dateTime, period, usd } from '@/lib/format';
import { cn } from '@/lib/utils';

interface TenderSource {
    name: string;
    url: string;
    keywords: string;
    active: boolean;
}

interface Props {
    tenant: {
        id: number;
        name: string;
        slug: string;
        domain: string | null;
        status: 'active' | 'suspended';
        url: string;
        profile: { legal_name: string | null; nuit: string | null; contact_email: string | null };
        budget: { tenant_monthly_usd: number | null; agent_monthly_usd: number | null; run_usd: number | null };
        mail_domain: string | null;
        email_retention_days: number;
        tender_sources: TenderSource[];
        business: Record<string, number>;
    };
    templates: { key: string; name: string; delivery: string; description: string; installed: boolean }[];
    usage: { month: string; spent_usd: number; runs: number };
    agents: {
        id: number;
        key: string;
        name: string;
        title: string | null;
        status: string;
        status_label: string;
        autonomy_level: number;
        spent_usd: number;
    }[];
    budgetEvents: {
        id: number;
        scope: string;
        period: string;
        threshold: number;
        spent_usd: number;
        cap_usd: number;
        agent: string | null;
        created_at: string;
    }[];
}

const businessFields: [string, string, string][] = [
    ['sla_response_hours', 'SLA de resposta a clientes (h)', 'Quando o contrato não define outro.'],
    ['deadline_warning_hours', 'Aviso de prazos (h antes)', 'Emails e concursos.'],
    ['contract_notice_days', 'Aviso de contratos (dias)', 'Por omissão em contratos novos.'],
    ['min_margin_pct', 'Margem mínima por projecto (%)', 'Abaixo disto, alerta.'],
    ['budget_alert_pct', 'Alerta de orçamento consumido (%)', 'Por projecto.'],
    ['unreconciled_days', 'Banco por reconciliar (dias)', 'Depois disto é um bloqueio.'],
];

const scopeLabel: Record<string, string> = { tenant: 'Organização', agent: 'Agente', run: 'Execução' };
const num = (value: number | null) => (value === null ? '' : String(value));

export default function TenantsShow({ tenant, usage, agents, budgetEvents, templates }: Props) {
    const form = useForm({
        name: tenant.name,
        domain: tenant.domain ?? '',
        status: tenant.status,
        profile: {
            legal_name: tenant.profile.legal_name ?? '',
            nuit: tenant.profile.nuit ?? '',
            contact_email: tenant.profile.contact_email ?? '',
        },
        budget: {
            tenant_monthly_usd: num(tenant.budget.tenant_monthly_usd),
            agent_monthly_usd: num(tenant.budget.agent_monthly_usd),
            run_usd: num(tenant.budget.run_usd),
        },
        mail_domain: tenant.mail_domain ?? '',
        email_retention_days: String(tenant.email_retention_days),
        tender_sources: tenant.tender_sources,
        business: Object.fromEntries(Object.entries(tenant.business).map(([key, value]) => [key, String(value)])) as Record<string, string>,
    });

    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put(`/tenants/${tenant.id}`, { preserveScroll: true });
    };

    const setSource = (index: number, patch: Partial<TenderSource>) =>
        form.setData(
            'tender_sources',
            form.data.tender_sources.map((source, i) => (i === index ? { ...source, ...patch } : source)),
        );

    const cap = tenant.budget.tenant_monthly_usd;
    const ratio = cap ? Math.min(usage.spent_usd / cap, 1) : 0;
    const host = tenant.url.replace(/^https?:\/\//, '');
    const createTemplates = (template?: string) =>
        router.post(`/tenants/${tenant.id}/agents/templates`, template ? { template } : {}, { preserveScroll: true });

    return (
        <AdminLayout title={tenant.name} breadcrumbs={[{ label: 'Organizações', href: '/tenants' }, { label: tenant.name }]}>
            <PageHeader
                title={
                    <span className="flex items-center gap-3">
                        {tenant.name}
                        <StatusBadge tone={tenant.status === 'active' ? 'success' : 'danger'}>
                            {tenant.status === 'active' ? 'Activa' : 'Suspensa'}
                        </StatusBadge>
                    </span>
                }
                description="Perfil da organização, orçamento de IA e agentes."
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={tenant.url} target="_blank" rel="noreferrer">
                                <ExternalLink />
                                Abrir consola
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={`/tenants/${tenant.id}/capabilities`}>
                                <Puzzle />
                                Capacidades
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="grid grid-cols-1 divide-y rounded-xl border bg-card sm:grid-cols-3 sm:divide-x sm:divide-y-0 [&>*]:min-w-0">
                <MetricCard
                    icon={CircleDollarSign}
                    value={<span className="">{usd(usage.spent_usd)}</span>}
                    label={`Gasto em IA (${period(usage.month)})`}
                    tone={ratio >= 1 ? 'danger' : ratio >= 0.8 ? 'warning' : undefined}
                    description={
                        cap !== null ? (
                            <span className="flex flex-col gap-1.5 pt-1">
                                <span className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <span
                                        className={cn(
                                            'block h-full rounded-full',
                                            ratio >= 1 ? 'bg-status-danger' : ratio >= 0.8 ? 'bg-status-warning' : 'bg-primary',
                                        )}
                                        style={{ width: `${ratio * 100}%` }}
                                    />
                                </span>
                                <span>
                                    de <span className="">{usd(cap)}</span> por mês
                                </span>
                            </span>
                        ) : (
                            'Sem tecto mensal.'
                        )
                    }
                />
                <MetricCard icon={Activity} value={usage.runs} label="Execuções este mês" />
                <MetricCard icon={Bot} value={agents.length} label="Agentes" />
            </div>

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-8">
                    {templates.some((t) => !t.installed) && (
                        <Section
                            title="Modelos de agentes"
                            action={
                                <Button size="sm" variant="outline" onClick={() => createTemplates()}>
                                    <Sparkles />
                                    Criar todos
                                </Button>
                            }
                        >
                            <p className="-mt-1 text-sm text-muted-foreground">
                                Os seis agentes da especificação, prontos a criar: instruções, capacidades, rotinas e caixa (desligada até ter
                                credenciais). Depois de criados, ajuste o que quiser.
                            </p>
                            <ListPanel>
                                {templates.map((t) => (
                                    <EntityRow
                                        key={t.key}
                                        leading={<Monogram name={t.name} agent />}
                                        title={t.name}
                                        subtitle={t.description}
                                        meta={<span>{t.delivery}</span>}
                                        trailing={
                                            t.installed ? (
                                                <StatusBadge tone="success">criado</StatusBadge>
                                            ) : (
                                                <Button size="sm" variant="outline" className="h-7" onClick={() => createTemplates(t.key)}>
                                                    Criar
                                                </Button>
                                            )
                                        }
                                    />
                                ))}
                            </ListPanel>
                        </Section>
                    )}

                    <Section
                        title="Agentes"
                        action={
                            <Button asChild size="sm">
                                <Link href={`/tenants/${tenant.id}/agents/create`}>
                                    <Plus />
                                    Novo agente
                                </Link>
                            </Button>
                        }
                    >
                        {agents.length === 0 ? (
                            <EmptyState
                                icon={Bot}
                                title="Sem agentes"
                                description="Crie o primeiro agente desta organização, ou instale os modelos de agentes acima."
                            />
                        ) : (
                            <ListPanel>
                                {agents.map((agent) => (
                                    <EntityRow
                                        key={agent.id}
                                        href={`/tenants/${tenant.id}/agents/${agent.id}/edit`}
                                        leading={<Monogram name={agent.name} agent />}
                                        title={agent.name}
                                        subtitle={agent.title ?? <span className="font-mono">{agent.key}</span>}
                                        meta={
                                            <>
                                                <AutonomyBadge level={agent.autonomy_level} />
                                                <span className="w-20 text-right tabular-nums" title="IA este mês">
                                                    {usd(agent.spent_usd)}
                                                </span>
                                            </>
                                        }
                                        trailing={<StatusBadge tone={agentTone(agent.status)}>{agent.status_label}</StatusBadge>}
                                    />
                                ))}
                            </ListPanel>
                        )}
                    </Section>

                    <Section title="Configuração">
                        <form onSubmit={submit} className="flex flex-col gap-6">
                            <FormBlock title="Perfil">
                                <div className="grid gap-4">
                                    <Field id="name" label="Nome" error={errors.name}>
                                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                                    </Field>
                                    <Field id="legal_name" label="Denominação social" error={errors['profile.legal_name']}>
                                        <Input
                                            id="legal_name"
                                            value={form.data.profile.legal_name}
                                            onChange={(e) => form.setData('profile', { ...form.data.profile, legal_name: e.target.value })}
                                        />
                                    </Field>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field id="nuit" label="NUIT" error={errors['profile.nuit']}>
                                            <Input
                                                id="nuit"
                                                className="font-mono"
                                                value={form.data.profile.nuit}
                                                onChange={(e) => form.setData('profile', { ...form.data.profile, nuit: e.target.value })}
                                            />
                                        </Field>
                                        <Field id="contact_email" label="Email de contacto" error={errors['profile.contact_email']}>
                                            <Input
                                                id="contact_email"
                                                type="email"
                                                value={form.data.profile.contact_email}
                                                onChange={(e) => form.setData('profile', { ...form.data.profile, contact_email: e.target.value })}
                                            />
                                        </Field>
                                    </div>
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field id="domain" label="Domínio próprio" error={errors.domain}>
                                            <Input
                                                id="domain"
                                                className="font-mono"
                                                value={form.data.domain}
                                                onChange={(e) => form.setData('domain', e.target.value)}
                                            />
                                        </Field>
                                        <Field id="status" label="Estado" error={errors.status}>
                                            <NativeSelect
                                                id="status"
                                                value={form.data.status}
                                                onChange={(e) => form.setData('status', e.target.value as 'active' | 'suspended')}
                                            >
                                                <option value="active">Activa</option>
                                                <option value="suspended">Suspensa</option>
                                            </NativeSelect>
                                        </Field>
                                    </div>
                                </div>
                            </FormBlock>

                            <FormBlock
                                title="Orçamento de IA"
                                description="Em USD. Aviso aos 80%; aos 100% o agente (ou todos, no tecto da organização) é suspenso. Vazio: sem tecto."
                            >
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field id="tenant_monthly_usd" label="Tecto mensal da organização" error={errors['budget.tenant_monthly_usd']}>
                                        <Input
                                            id="tenant_monthly_usd"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            className="font-mono"
                                            value={form.data.budget.tenant_monthly_usd}
                                            onChange={(e) => form.setData('budget', { ...form.data.budget, tenant_monthly_usd: e.target.value })}
                                        />
                                    </Field>
                                    <Field id="agent_monthly_usd" label="Tecto mensal por agente" error={errors['budget.agent_monthly_usd']}>
                                        <Input
                                            id="agent_monthly_usd"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            className="font-mono"
                                            value={form.data.budget.agent_monthly_usd}
                                            onChange={(e) => form.setData('budget', { ...form.data.budget, agent_monthly_usd: e.target.value })}
                                        />
                                    </Field>
                                    <Field id="run_usd" label="Tecto por execução" error={errors['budget.run_usd']}>
                                        <Input
                                            id="run_usd"
                                            type="number"
                                            min="0"
                                            step="0.01"
                                            className="font-mono"
                                            value={form.data.budget.run_usd}
                                            onChange={(e) => form.setData('budget', { ...form.data.budget, run_usd: e.target.value })}
                                        />
                                    </Field>
                                </div>
                            </FormBlock>

                            <FormBlock
                                title="Email e concursos"
                                description="Domínio das caixas dos agentes, retenção do email bruto e fontes de concursos que a triagem vigia."
                            >
                                <div className="grid gap-5">
                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <Field
                                            id="mail_domain"
                                            label="Domínio das caixas"
                                            error={errors.mail_domain}
                                            hint="Ex.: agentes.micomoc.co.mz"
                                        >
                                            <Input
                                                id="mail_domain"
                                                className="font-mono"
                                                value={form.data.mail_domain}
                                                onChange={(e) => form.setData('mail_domain', e.target.value)}
                                            />
                                        </Field>
                                        <Field id="email_retention_days" label="Retenção do email bruto (dias)" error={errors.email_retention_days}>
                                            <Input
                                                id="email_retention_days"
                                                type="number"
                                                min="7"
                                                className="font-mono"
                                                value={form.data.email_retention_days}
                                                onChange={(e) => form.setData('email_retention_days', e.target.value)}
                                            />
                                        </Field>
                                    </div>

                                    <div className="grid gap-2">
                                        <p className="text-sm font-medium">Fontes de concursos</p>
                                        {form.data.tender_sources.length === 0 ? (
                                            <p className="rounded-lg border border-dashed px-4 py-3 text-sm text-muted-foreground">
                                                Nenhuma fonte configurada. Adicione o portal de concursos que a triagem deve vigiar.
                                            </p>
                                        ) : (
                                            <div className="divide-y rounded-lg border">
                                                {form.data.tender_sources.map((source, index) => (
                                                    <div key={index} className="grid items-start gap-2 p-3 sm:grid-cols-[1fr_2fr_2fr_auto_auto]">
                                                        <Input
                                                            placeholder="Nome"
                                                            aria-label="Nome"
                                                            value={source.name}
                                                            onChange={(e) => setSource(index, { name: e.target.value })}
                                                            aria-invalid={!!errors[`tender_sources.${index}.name`]}
                                                        />
                                                        <Input
                                                            placeholder="https://…"
                                                            aria-label="Endereço"
                                                            className="font-mono text-xs"
                                                            value={source.url}
                                                            onChange={(e) => setSource(index, { url: e.target.value })}
                                                            aria-invalid={!!errors[`tender_sources.${index}.url`]}
                                                        />
                                                        <Input
                                                            placeholder="Palavras-chave, separadas por vírgulas"
                                                            aria-label="Palavras-chave"
                                                            value={source.keywords}
                                                            onChange={(e) => setSource(index, { keywords: e.target.value })}
                                                        />
                                                        <label className="flex h-9 items-center gap-2 text-sm">
                                                            <Checkbox
                                                                checked={source.active}
                                                                onCheckedChange={(checked) => setSource(index, { active: checked === true })}
                                                            />
                                                            Activa
                                                        </label>
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label="Remover fonte"
                                                            className="text-muted-foreground hover:text-status-danger"
                                                            onClick={() =>
                                                                form.setData(
                                                                    'tender_sources',
                                                                    form.data.tender_sources.filter((_, i) => i !== index),
                                                                )
                                                            }
                                                        >
                                                            <Trash2 />
                                                        </Button>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                        <div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    form.setData('tender_sources', [
                                                        ...form.data.tender_sources,
                                                        { name: '', url: '', keywords: '', active: true },
                                                    ])
                                                }
                                            >
                                                <Plus />
                                                Adicionar fonte
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </FormBlock>

                            <FormBlock
                                title="Regras de negócio"
                                description="Limites que os agentes e as vigilâncias usam. Vazio volta ao valor por omissão."
                            >
                                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                    {businessFields.map(([key, label, hint]) => (
                                        <Field key={key} id={`business-${key}`} label={label} hint={hint} error={errors[`business.${key}`]}>
                                            <Input
                                                id={`business-${key}`}
                                                type="number"
                                                min="0"
                                                className="font-mono"
                                                value={form.data.business[key] ?? ''}
                                                onChange={(e) => form.setData('business', { ...form.data.business, [key]: e.target.value })}
                                            />
                                        </Field>
                                    ))}
                                </div>
                            </FormBlock>

                            <div className="flex items-center justify-between gap-3">
                                <span className="text-xs text-muted-foreground">
                                    {form.isDirty ? 'Há alterações por guardar.' : 'Sem alterações.'}
                                </span>
                                <Button type="submit" disabled={form.processing}>
                                    Guardar perfil
                                </Button>
                            </div>
                        </form>
                    </Section>

                    <Section title="Eventos de orçamento">
                        {budgetEvents.length === 0 ? (
                            <EmptyState
                                icon={Gauge}
                                title="Nenhum limiar atingido"
                                description="Quando um gasto chegar a 80% ou 100% de um tecto, o evento aparece aqui."
                            />
                        ) : (
                            <ListPanel>
                                {budgetEvents.map((event) => (
                                    <EntityRow
                                        key={event.id}
                                        leading={
                                            <Gauge className={cn('size-4', event.threshold >= 100 ? 'text-status-danger' : 'text-status-warning')} />
                                        }
                                        title={
                                            <>
                                                {scopeLabel[event.scope] ?? event.scope}
                                                {event.agent && <span className="font-normal text-muted-foreground"> · {event.agent}</span>}
                                            </>
                                        }
                                        subtitle={
                                            <span className="tabular-nums">
                                                {usd(event.spent_usd)} / {usd(event.cap_usd)}
                                            </span>
                                        }
                                        meta={<span title={dateTime(event.created_at)}>{ago(event.created_at)}</span>}
                                        trailing={
                                            <StatusBadge tone={event.threshold >= 100 ? 'danger' : 'warning'}>
                                                <span className="font-mono">{event.threshold}%</span>
                                            </StatusBadge>
                                        }
                                    />
                                ))}
                            </ListPanel>
                        )}
                    </Section>
                </div>

                <div className="lg:sticky lg:top-16 lg:self-start">
                    <Properties>
                        <Property label="Estado">
                            <StatusBadge tone={tenant.status === 'active' ? 'success' : 'danger'}>
                                {tenant.status === 'active' ? 'Activa' : 'Suspensa'}
                            </StatusBadge>
                        </Property>
                        <Property label="Consola">
                            {/* One line, ending in "…" if the column is narrow, never broken inside the port. */}
                            <a
                                href={tenant.url}
                                target="_blank"
                                rel="noreferrer"
                                className="block truncate font-mono text-xs whitespace-nowrap hover:underline"
                                title={host}
                            >
                                {host}
                            </a>
                        </Property>
                        <Property label="Subdomínio">
                            <span className="font-mono text-xs">{tenant.slug}</span>
                        </Property>
                        <Property label="Domínio próprio">{tenant.domain && <span className="font-mono text-xs">{tenant.domain}</span>}</Property>
                        <Property label="Denominação">{tenant.profile.legal_name}</Property>
                        <Property label="NUIT">{tenant.profile.nuit && <span className="font-mono text-xs">{tenant.profile.nuit}</span>}</Property>
                        <Property label="Contacto">{tenant.profile.contact_email}</Property>
                        <Property label="Caixas">{tenant.mail_domain && <span className="font-mono text-xs">{tenant.mail_domain}</span>}</Property>
                        <Property label="Tecto mensal">
                            {cap !== null ? <span className="text-xs">{usd(cap)}</span> : <span className="text-muted-foreground">Sem tecto</span>}
                        </Property>
                        <Property label="ID">
                            <span className="font-mono text-xs">#{tenant.id}</span>
                        </Property>
                    </Properties>
                </div>
            </div>
        </AdminLayout>
    );
}

function FormBlock({ title, description, children }: { title: string; description?: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border bg-card p-5">
            <div className="mb-5 space-y-1">
                <h3 className="text-sm font-semibold">{title}</h3>
                {description && <p className="text-sm text-muted-foreground">{description}</p>}
            </div>
            {children}
        </section>
    );
}
