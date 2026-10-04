import { Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Lock, Plus, Trash2 } from 'lucide-react';
import { type FormEvent, useMemo, useState } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import { dateTime } from '@/lib/format';
import type { LevelOption, Option } from '@/types';

interface SkillOption {
    id: number;
    key: string;
    name: string;
    description: string | null;
    source: 'local' | 'mcp';
    is_mutating: boolean;
    is_available: boolean;
    risk: number;
    ceiling: boolean;
}

interface AgentData {
    id: number;
    key: string;
    name: string;
    title: string | null;
    description: string | null;
    personality: string | null;
    instructions: string | null;
    department_id: number | null;
    reports_to_user_id: number | null;
    provider: string | null;
    model: string | null;
    temperature: number | null;
    max_tokens: number | null;
    max_steps: number | null;
    status: string;
    autonomy_level: number;
    skills: number[];
}

interface Routine {
    id: number;
    name: string;
    prompt: string;
    schedule: string;
    is_active: boolean;
    last_run_at: string | null;
}

interface Mailbox {
    id: number;
    address: string;
    display_name: string;
    status: string;
    imap_host: string | null;
    imap_port: number | null;
    imap_username: string | null;
    imap_encryption: string | null;
    smtp_host: string | null;
    smtp_port: number | null;
    smtp_username: string | null;
    smtp_encryption: string | null;
    has_imap_password: boolean;
    has_smtp_password: boolean;
    last_error: string | null;
}

interface Props {
    tenant: { id: number; name: string };
    agent: AgentData | null;
    departments: { id: number; name: string }[];
    users: { id: number; name: string }[];
    skills: SkillOption[];
    levels: LevelOption[];
    statuses: Option[];
    providers: string[];
    defaultProvider: string;
    routines?: Routine[];
    mailbox?: Mailbox | null;
}

const str = (value: number | string | null | undefined) => (value === null || value === undefined ? '' : String(value));

export default function AgentForm({ tenant, agent, departments, users, skills, levels, statuses, providers, defaultProvider, routines = [], mailbox }: Props) {
    const base = `/tenants/${tenant.id}/agents`;
    const form = useForm({
        key: agent?.key ?? '',
        name: agent?.name ?? '',
        title: agent?.title ?? '',
        description: agent?.description ?? '',
        personality: agent?.personality ?? '',
        instructions: agent?.instructions ?? '',
        department_id: str(agent?.department_id),
        reports_to_user_id: str(agent?.reports_to_user_id),
        status: agent?.status ?? 'draft',
        autonomy_level: str(agent?.autonomy_level ?? 1),
        provider: agent?.provider ?? '',
        model: agent?.model ?? '',
        temperature: str(agent?.temperature),
        max_tokens: str(agent?.max_tokens),
        max_steps: str(agent?.max_steps),
        skills: agent?.skills ?? [],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const [filter, setFilter] = useState('');

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            department_id: data.department_id || null,
            reports_to_user_id: data.reports_to_user_id || null,
            provider: data.provider || null,
            model: data.model || null,
            temperature: data.temperature || null,
            max_tokens: data.max_tokens || null,
            max_steps: data.max_steps || null,
        }));

        if (agent) {
            form.put(`${base}/${agent.id}`, { preserveScroll: true });
        } else {
            form.post(base);
        }
    };

    const level = Number(form.data.autonomy_level);
    const visibleSkills = useMemo(
        () => skills.filter((skill) => `${skill.key} ${skill.name}`.toLowerCase().includes(filter.toLowerCase())),
        [skills, filter],
    );

    const toggleSkill = (id: number, on: boolean) =>
        form.setData('skills', on ? [...form.data.skills, id] : form.data.skills.filter((skillId) => skillId !== id));

    return (
        <AdminLayout title={agent ? agent.name : 'Novo agente'}>
            <div>
                <Link href={`/tenants/${tenant.id}`} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    {tenant.name}
                </Link>
            </div>
            <PageHeader
                title={agent ? agent.name : 'Novo agente'}
                description="A definição do agente: quem é, como fala, o que faz, com que modelo, que skills usa e até onde pode agir sozinho."
            />

            <form onSubmit={submit} className="grid gap-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Identidade</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field id="name" label="Nome" error={errors.name}>
                                    <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                                </Field>
                                <Field id="key" label="Chave" error={errors.key} hint="Identificador único: triagem, chief-of-staff…">
                                    <Input id="key" value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} required />
                                </Field>
                            </div>
                            <Field id="title" label="Função" error={errors.title}>
                                <Input id="title" placeholder="Agente Comercial e de Triagem" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                            </Field>
                            <Field id="description" label="Descrição" error={errors.description}>
                                <Textarea id="description" rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field id="department_id" label="Departamento" error={errors.department_id}>
                                    <NativeSelect id="department_id" value={form.data.department_id} onChange={(e) => form.setData('department_id', e.target.value)}>
                                        <option value="">—</option>
                                        {departments.map((department) => (
                                            <option key={department.id} value={department.id}>
                                                {department.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="reports_to_user_id" label="Responde a" error={errors.reports_to_user_id}>
                                    <NativeSelect id="reports_to_user_id" value={form.data.reports_to_user_id} onChange={(e) => form.setData('reports_to_user_id', e.target.value)}>
                                        <option value="">—</option>
                                        {users.map((user) => (
                                            <option key={user.id} value={user.id}>
                                                {user.name}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Autonomia e modelo</CardTitle>
                            <CardDescription>O tecto absoluto (pagamentos, facturas, contratos, pessoas, permissões) pede sempre aprovação.</CardDescription>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-4">
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field id="status" label="Estado" error={errors.status}>
                                    <NativeSelect id="status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                        {statuses.map((status) => (
                                            <option key={status.value} value={status.value}>
                                                {status.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="autonomy_level" label="Nível de autonomia" error={errors.autonomy_level}>
                                    <NativeSelect id="autonomy_level" value={form.data.autonomy_level} onChange={(e) => form.setData('autonomy_level', e.target.value)}>
                                        {levels.map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.code} · {option.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field id="provider" label="Provedor" error={errors.provider}>
                                    <NativeSelect id="provider" value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}>
                                        <option value="">Por omissão ({defaultProvider})</option>
                                        {providers.map((provider) => (
                                            <option key={provider} value={provider}>
                                                {provider}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </Field>
                                <Field id="model" label="Modelo" error={errors.model} hint="Vazio: o modelo por omissão do provedor.">
                                    <Input id="model" value={form.data.model} onChange={(e) => form.setData('model', e.target.value)} />
                                </Field>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Field id="temperature" label="Temperatura" error={errors.temperature}>
                                    <Input id="temperature" type="number" step="0.1" min="0" max="2" value={form.data.temperature} onChange={(e) => form.setData('temperature', e.target.value)} />
                                </Field>
                                <Field id="max_tokens" label="Máx. tokens" error={errors.max_tokens}>
                                    <Input id="max_tokens" type="number" min="1" value={form.data.max_tokens} onChange={(e) => form.setData('max_tokens', e.target.value)} />
                                </Field>
                                <Field id="max_steps" label="Máx. passos" error={errors.max_steps}>
                                    <Input id="max_steps" type="number" min="1" max="50" value={form.data.max_steps} onChange={(e) => form.setData('max_steps', e.target.value)} />
                                </Field>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Personalidade e instruções</CardTitle>
                        <CardDescription>Entram no prompt de sistema, depois da identidade e antes das regras da plataforma, que não se podem sobrepor.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-4 grid gap-4">
                        <Field id="personality" label="Personalidade" error={errors.personality}>
                            <Textarea id="personality" rows={3} value={form.data.personality} onChange={(e) => form.setData('personality', e.target.value)} />
                        </Field>
                        <Field id="instructions" label="Instruções" error={errors.instructions}>
                            <Textarea id="instructions" rows={10} className="font-mono text-xs" value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} />
                        </Field>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-start justify-between gap-4">
                        <div className="space-y-1.5">
                            <CardTitle>Skills</CardTitle>
                            <CardDescription>
                                {form.data.skills.length} seleccionada(s). Sem aprovação, o agente só usa uma skill de escrita se o seu nível for igual ou superior ao risco dela.
                            </CardDescription>
                        </div>
                        <Input className="max-w-56" placeholder="Filtrar…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                    </CardHeader>
                    <CardContent className="mt-4">
                        <InputErrorList errors={errors} prefix="skills" />
                        <ul className="grid max-h-[28rem] gap-1 overflow-y-auto rounded-md border p-2 sm:grid-cols-2">
                            {visibleSkills.map((skill) => {
                                const checked = form.data.skills.includes(skill.id);
                                const gated = skill.ceiling || (skill.is_mutating && level < skill.risk);

                                return (
                                    <li key={skill.id}>
                                        <label className="flex cursor-pointer items-start gap-3 rounded px-2 py-2 hover:bg-muted">
                                            <Checkbox checked={checked} disabled={!skill.is_available} onCheckedChange={(on) => toggleSkill(skill.id, on === true)} className="mt-0.5" />
                                            <span className="min-w-0 flex-1">
                                                <span className="flex flex-wrap items-center gap-1.5">
                                                    <span className="text-sm font-medium">{skill.name}</span>
                                                    <Badge variant="outline">{skill.source === 'mcp' ? 'ERP' : 'local'}</Badge>
                                                    {skill.is_mutating ? <AutonomyBadge level={skill.risk} /> : <Badge variant="secondary">leitura</Badge>}
                                                    {skill.ceiling && (
                                                        <Badge variant="destructive">
                                                            <Lock />
                                                            tecto
                                                        </Badge>
                                                    )}
                                                    {!skill.is_available && <Badge variant="outline">indisponível</Badge>}
                                                </span>
                                                <span className="block font-mono text-[11px] text-muted-foreground">{skill.key}</span>
                                                {checked && gated && <span className="block text-xs text-amber-700">Pede aprovação a este nível.</span>}
                                            </span>
                                        </label>
                                    </li>
                                );
                            })}
                        </ul>
                    </CardContent>
                    <CardFooter className="mt-6 justify-end">
                        <Button type="submit" disabled={form.processing}>
                            {agent ? 'Guardar agente' : 'Criar agente'}
                        </Button>
                    </CardFooter>
                </Card>
            </form>

            {agent && <Routines base={`${base}/${agent.id}/routines`} routines={routines} />}
            {agent && <MailboxForm action={`${base}/${agent.id}/mailbox`} mailbox={mailbox ?? null} />}
        </AdminLayout>
    );
}

function InputErrorList({ errors, prefix }: { errors: Record<string, string | undefined>; prefix: string }) {
    const messages = Object.entries(errors)
        .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
        .map(([, message]) => message);

    return messages.length ? <p className="mb-3 text-sm text-destructive">{messages[0]}</p> : null;
}

function Routines({ base, routines }: { base: string; routines: Routine[] }) {
    const form = useForm({ name: '', prompt: '', schedule: '0 7 * * 1-5', is_active: true });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(base, { preserveScroll: true, onSuccess: () => form.reset() });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Rotinas</CardTitle>
                <CardDescription>Acções de rotina: um pedido que o agente executa num horário (expressão cron, hora de Maputo).</CardDescription>
            </CardHeader>
            <CardContent className="mt-4 grid gap-4">
                {routines.length === 0 && <p className="text-sm text-muted-foreground">Sem rotinas.</p>}
                {routines.map((routine) => (
                    <RoutineRow key={routine.id} base={base} routine={routine} />
                ))}

                <form onSubmit={submit} className="grid gap-3 rounded-md border border-dashed p-4">
                    <p className="text-sm font-medium">Nova rotina</p>
                    <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                        <Field id="routine-name" label="Nome" error={form.errors.name}>
                            <Input id="routine-name" placeholder="Briefing diário" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} />
                        </Field>
                        <Field id="routine-schedule" label="Horário (cron)" error={form.errors.schedule}>
                            <Input id="routine-schedule" className="font-mono" value={form.data.schedule} onChange={(e) => form.setData('schedule', e.target.value)} />
                        </Field>
                    </div>
                    <Field id="routine-prompt" label="Pedido" error={form.errors.prompt}>
                        <Textarea id="routine-prompt" rows={3} value={form.data.prompt} onChange={(e) => form.setData('prompt', e.target.value)} />
                    </Field>
                    <div className="flex justify-end">
                        <Button type="submit" size="sm" disabled={form.processing}>
                            <Plus />
                            Adicionar rotina
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function RoutineRow({ base, routine }: { base: string; routine: Routine }) {
    const form = useForm({ name: routine.name, prompt: routine.prompt, schedule: routine.schedule, is_active: routine.is_active });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.put(`${base}/${routine.id}`, { preserveScroll: true });
            }}
            className="grid gap-3 rounded-md border p-4"
        >
            <div className="grid gap-3 sm:grid-cols-[2fr_1fr_auto]">
                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-label="Nome" />
                <Input className="font-mono" value={form.data.schedule} onChange={(e) => form.setData('schedule', e.target.value)} aria-label="Horário" aria-invalid={!!form.errors.schedule} />
                <label className="flex h-9 items-center gap-2 text-sm">
                    <Checkbox checked={form.data.is_active} onCheckedChange={(on) => form.setData('is_active', on === true)} />
                    Activa
                </label>
            </div>
            {form.errors.schedule && <p className="text-sm text-destructive">{form.errors.schedule}</p>}
            <Textarea rows={2} value={form.data.prompt} onChange={(e) => form.setData('prompt', e.target.value)} aria-label="Pedido" />
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">Última execução: {dateTime(routine.last_run_at)}</p>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => confirm('Apagar esta rotina?') && router.delete(`${base}/${routine.id}`, { preserveScroll: true })}
                    >
                        <Trash2 />
                        Apagar
                    </Button>
                    <Button type="submit" size="sm" variant="outline" disabled={form.processing || !form.isDirty}>
                        Guardar
                    </Button>
                </div>
            </div>
        </form>
    );
}

function MailboxForm({ action, mailbox }: { action: string; mailbox: Mailbox | null }) {
    const form = useForm({
        address: mailbox?.address ?? '',
        display_name: mailbox?.display_name ?? '',
        status: mailbox?.status ?? 'provisioning',
        imap_host: mailbox?.imap_host ?? 'imap.hostinger.com',
        imap_port: str(mailbox?.imap_port ?? 993),
        imap_username: mailbox?.imap_username ?? '',
        imap_password: '',
        imap_encryption: mailbox?.imap_encryption ?? 'ssl',
        smtp_host: mailbox?.smtp_host ?? 'smtp.hostinger.com',
        smtp_port: str(mailbox?.smtp_port ?? 465),
        smtp_username: mailbox?.smtp_username ?? '',
        smtp_password: '',
        smtp_encryption: mailbox?.smtp_encryption ?? 'ssl',
    });

    const text = (key: keyof typeof form.data, label: string, type = 'text', placeholder?: string) => (
        <Field id={`mb-${key}`} label={label} error={form.errors[key]}>
            <Input id={`mb-${key}`} type={type} placeholder={placeholder} autoComplete="off" value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} />
        </Field>
    );

    const encryption = (key: 'imap_encryption' | 'smtp_encryption') => (
        <Field id={`mb-${key}`} label="Cifra" error={form.errors[key]}>
            <NativeSelect id={`mb-${key}`} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)}>
                <option value="ssl">SSL</option>
                <option value="tls">STARTTLS</option>
                <option value="none">Nenhuma</option>
            </NativeSelect>
        </Field>
    );

    return (
        <Card>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(action, { preserveScroll: true, onSuccess: () => form.reset('imap_password', 'smtp_password') });
                }}
            >
                <CardHeader>
                    <CardTitle>Caixa de correio</CardTitle>
                    <CardDescription>A caixa própria do agente (Hostinger): IMAP para receber, SMTP para enviar. As palavras-passe ficam encriptadas.</CardDescription>
                </CardHeader>
                <CardContent className="mt-4 grid gap-4">
                    {mailbox?.last_error && (
                        <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">{mailbox.last_error}</div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-3">
                        {text('address', 'Endereço', 'email', 'triagem@agentes.exemplo.co.mz')}
                        {text('display_name', 'Nome apresentado')}
                        <Field id="mb-status" label="Estado" error={form.errors.status}>
                            <NativeSelect id="mb-status" value={form.data.status} onChange={(e) => form.setData('status', e.target.value)}>
                                <option value="provisioning">Em preparação</option>
                                <option value="active">Activa</option>
                                <option value="error">Com erro</option>
                                <option value="disabled">Desactivada</option>
                            </NativeSelect>
                        </Field>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-5">
                        {text('imap_host', 'Servidor IMAP')}
                        {text('imap_port', 'Porta', 'number')}
                        {encryption('imap_encryption')}
                        {text('imap_username', 'Utilizador')}
                        {text('imap_password', 'Palavra-passe', 'password', mailbox?.has_imap_password ? 'Guardada' : undefined)}
                    </div>
                    <div className="grid gap-4 sm:grid-cols-5">
                        {text('smtp_host', 'Servidor SMTP')}
                        {text('smtp_port', 'Porta', 'number')}
                        {encryption('smtp_encryption')}
                        {text('smtp_username', 'Utilizador')}
                        {text('smtp_password', 'Palavra-passe', 'password', mailbox?.has_smtp_password ? 'Guardada' : undefined)}
                    </div>
                </CardContent>
                <CardFooter className="mt-6 justify-end">
                    <Button type="submit" disabled={form.processing}>
                        Guardar caixa
                    </Button>
                </CardFooter>
            </form>
        </Card>
    );
}
