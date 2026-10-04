import { Link, router, useForm } from '@inertiajs/react';
import { Bot, ExternalLink, Plus, Puzzle, Sparkles, Trash2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import AdminLayout from '@/Layouts/AdminLayout';
import { dateTime, usd } from '@/lib/format';

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

    return (
        <AdminLayout title={tenant.name}>
            <PageHeader
                title={tenant.name}
                description={`Perfil da organização, orçamento de IA e agentes. Consola em ${tenant.url.replace(/^https?:\/\//, '')}.`}
                actions={
                    <>
                        <Button variant="outline" asChild>
                            <a href={tenant.url} target="_blank" rel="noreferrer">
                                <ExternalLink />
                                Abrir consola
                            </a>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={`/tenants/${tenant.id}/skills`}>
                                <Puzzle />
                                Skills
                            </Link>
                        </Button>
                    </>
                }
            />

            <div className="grid gap-4 sm:grid-cols-3">
                <Card>
                    <CardHeader>
                        <CardDescription>Gasto em IA ({usage.month})</CardDescription>
                        <CardTitle className="text-2xl tabular-nums">{usd(usage.spent_usd)}</CardTitle>
                    </CardHeader>
                    {cap !== null && (
                        <CardContent>
                            <div className="h-2 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={ratio >= 1 ? 'h-full bg-destructive' : ratio >= 0.8 ? 'h-full bg-amber-500' : 'h-full bg-primary'}
                                    style={{ width: `${ratio * 100}%` }}
                                />
                            </div>
                            <p className="mt-2 text-xs text-muted-foreground">de {usd(cap)} por mês</p>
                        </CardContent>
                    )}
                </Card>
                <Card>
                    <CardHeader>
                        <CardDescription>Execuções este mês</CardDescription>
                        <CardTitle className="text-2xl tabular-nums">{usage.runs}</CardTitle>
                    </CardHeader>
                </Card>
                <Card>
                    <CardHeader>
                        <CardDescription>Agentes</CardDescription>
                        <CardTitle className="text-2xl tabular-nums">{agents.length}</CardTitle>
                    </CardHeader>
                </Card>
            </div>

            {templates.some((t) => !t.installed) && (
                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1.5">
                            <CardTitle>Modelos de agentes</CardTitle>
                            <CardDescription>Os seis agentes da especificação, prontos a criar: instruções, skills, rotinas e caixa (desligada até ter credenciais). Depois de criados, ajuste o que quiser.</CardDescription>
                        </div>
                        <Button size="sm" onClick={() => router.post(`/tenants/${tenant.id}/agents/templates`, {}, { preserveScroll: true })}>
                            <Sparkles />
                            Criar todos
                        </Button>
                    </CardHeader>
                    <CardContent className="mt-4 grid gap-2 sm:grid-cols-2">
                        {templates.map((t) => (
                            <div key={t.key} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                <div className="min-w-0">
                                    <p className="text-sm font-medium">
                                        {t.name} <span className="text-xs text-muted-foreground">{t.delivery}</span>
                                    </p>
                                    <p className="line-clamp-2 text-xs text-muted-foreground">{t.description}</p>
                                </div>
                                {t.installed ? (
                                    <Badge variant="secondary">criado</Badge>
                                ) : (
                                    <Button size="sm" variant="outline" onClick={() => router.post(`/tenants/${tenant.id}/agents/templates`, { template: t.key }, { preserveScroll: true })}>
                                        Criar
                                    </Button>
                                )}
                            </div>
                        ))}
                    </CardContent>
                </Card>
            )}

            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-4">
                    <div className="space-y-1.5">
                        <CardTitle>Agentes</CardTitle>
                        <CardDescription>Agentes genéricos: identidade, personalidade, instruções, skills, rotinas e nível de autonomia.</CardDescription>
                    </div>
                    <Button asChild size="sm">
                        <Link href={`/tenants/${tenant.id}/agents/create`}>
                            <Plus />
                            Novo agente
                        </Link>
                    </Button>
                </CardHeader>
                <CardContent className="mt-4">
                    {agents.length === 0 ? (
                        <EmptyState icon={Bot} title="Sem agentes" description="Crie o primeiro agente desta organização." />
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Agente</TableHead>
                                    <TableHead>Estado</TableHead>
                                    <TableHead>Autonomia</TableHead>
                                    <TableHead className="text-right">IA este mês</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {agents.map((agent) => (
                                    <TableRow key={agent.id}>
                                        <TableCell>
                                            <Link href={`/tenants/${tenant.id}/agents/${agent.id}/edit`} className="font-medium hover:underline">
                                                {agent.name}
                                            </Link>
                                            <p className="text-xs text-muted-foreground">{agent.title ?? agent.key}</p>
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={agent.status === 'active' ? 'secondary' : agent.status === 'suspended' ? 'destructive' : 'outline'}>
                                                {agent.status_label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell>
                                            <AutonomyBadge level={agent.autonomy_level} withLabel />
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">{usd(agent.spent_usd)}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>

            <form onSubmit={submit} className="grid gap-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Perfil</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-4">
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
                                    <Input id="nuit" value={form.data.profile.nuit} onChange={(e) => form.setData('profile', { ...form.data.profile, nuit: e.target.value })} />
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
                                    <Input id="domain" value={form.data.domain} onChange={(e) => form.setData('domain', e.target.value)} />
                                </Field>
                                <Field id="status" label="Estado" error={errors.status}>
                                    <NativeSelect id="status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value as 'active' | 'suspended')}>
                                        <option value="active">Activa</option>
                                        <option value="suspended">Suspensa</option>
                                    </NativeSelect>
                                </Field>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Orçamento de IA</CardTitle>
                            <CardDescription>Em USD. Aviso aos 80%; aos 100% o agente (ou todos, no tecto da organização) é suspenso. Vazio: sem tecto.</CardDescription>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-4">
                            <Field id="tenant_monthly_usd" label="Tecto mensal da organização" error={errors['budget.tenant_monthly_usd']}>
                                <Input
                                    id="tenant_monthly_usd"
                                    type="number"
                                    min="0"
                                    step="0.01"
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
                                    value={form.data.budget.run_usd}
                                    onChange={(e) => form.setData('budget', { ...form.data.budget, run_usd: e.target.value })}
                                />
                            </Field>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Email e concursos</CardTitle>
                        <CardDescription>Domínio das caixas dos agentes, retenção do email bruto e fontes de concursos que a triagem vigia.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4 grid gap-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="mail_domain" label="Domínio das caixas" error={errors.mail_domain} hint="Ex.: agentes.micomoc.co.mz">
                                <Input id="mail_domain" value={form.data.mail_domain} onChange={(e) => form.setData('mail_domain', e.target.value)} />
                            </Field>
                            <Field id="email_retention_days" label="Retenção do email bruto (dias)" error={errors.email_retention_days}>
                                <Input
                                    id="email_retention_days"
                                    type="number"
                                    min="7"
                                    value={form.data.email_retention_days}
                                    onChange={(e) => form.setData('email_retention_days', e.target.value)}
                                />
                            </Field>
                        </div>

                        <div className="grid gap-3">
                            <p className="text-sm font-medium">Fontes de concursos</p>
                            {form.data.tender_sources.length === 0 && <p className="text-sm text-muted-foreground">Nenhuma fonte configurada.</p>}
                            {form.data.tender_sources.map((source, index) => (
                                <div key={index} className="grid items-start gap-2 rounded-md border p-3 sm:grid-cols-[1fr_2fr_2fr_auto_auto]">
                                    <Input placeholder="Nome" value={source.name} onChange={(e) => setSource(index, { name: e.target.value })} aria-invalid={!!errors[`tender_sources.${index}.name`]} />
                                    <Input placeholder="https://…" value={source.url} onChange={(e) => setSource(index, { url: e.target.value })} aria-invalid={!!errors[`tender_sources.${index}.url`]} />
                                    <Input placeholder="Palavras-chave, separadas por vírgulas" value={source.keywords} onChange={(e) => setSource(index, { keywords: e.target.value })} />
                                    <label className="flex h-9 items-center gap-2 text-sm">
                                        <Checkbox checked={source.active} onCheckedChange={(checked) => setSource(index, { active: checked === true })} />
                                        Activa
                                    </label>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        aria-label="Remover fonte"
                                        onClick={() => form.setData('tender_sources', form.data.tender_sources.filter((_, i) => i !== index))}
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            ))}
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    onClick={() => form.setData('tender_sources', [...form.data.tender_sources, { name: '', url: '', keywords: '', active: true }])}
                                >
                                    <Plus />
                                    Adicionar fonte
                                </Button>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Regras de negócio</CardTitle>
                        <CardDescription>Limites que os agentes e as vigilâncias usam. Vazio volta ao valor por omissão.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4 grid gap-4 sm:grid-cols-3">
                        {businessFields.map(([key, label, hint]) => (
                            <Field key={key} id={`business-${key}`} label={label} hint={hint} error={errors[`business.${key}`]}>
                                <Input
                                    id={`business-${key}`}
                                    type="number"
                                    min="0"
                                    value={form.data.business[key] ?? ''}
                                    onChange={(e) => form.setData('business', { ...form.data.business, [key]: e.target.value })}
                                />
                            </Field>
                        ))}
                    </CardContent>
                    <CardFooter className="mt-6 justify-end">
                        <Button type="submit" disabled={form.processing}>
                            Guardar perfil
                        </Button>
                    </CardFooter>
                </Card>
            </form>

            <Card>
                <CardHeader>
                    <CardTitle>Eventos de orçamento</CardTitle>
                    <CardDescription>Limiares atingidos (80% e 100%).</CardDescription>
                </CardHeader>
                <CardContent className="mt-4">
                    {budgetEvents.length === 0 ? (
                        <p className="text-sm text-muted-foreground">Nenhum limiar atingido.</p>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Quando</TableHead>
                                    <TableHead>Âmbito</TableHead>
                                    <TableHead>Limiar</TableHead>
                                    <TableHead className="text-right">Gasto / tecto</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {budgetEvents.map((event) => (
                                    <TableRow key={event.id}>
                                        <TableCell>{dateTime(event.created_at)}</TableCell>
                                        <TableCell>
                                            {scopeLabel[event.scope] ?? event.scope}
                                            {event.agent && <span className="text-muted-foreground"> · {event.agent}</span>}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={event.threshold >= 100 ? 'destructive' : 'secondary'}>{event.threshold}%</Badge>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {usd(event.spent_usd)} / {usd(event.cap_usd)}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
        </AdminLayout>
    );
}
