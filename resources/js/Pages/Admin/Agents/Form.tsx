import { Link, router, useForm } from '@inertiajs/react';
import { Bot, Brain, CalendarClock, Cpu, Lock, Mail, Plus, Puzzle, Search, Trash2, type LucideIcon } from 'lucide-react';
import { type FormEvent, type ReactNode, useEffect, useMemo, useState } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { Monogram } from '@/Components/Blocks';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { agentTone, StatusBadge, StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AdminLayout from '@/Layouts/AdminLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { LevelOption, Option } from '@/types';

interface CapabilityOption {
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
    capabilities: number[];
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
    capabilities: CapabilityOption[];
    levels: LevelOption[];
    statuses: Option[];
    providers: string[];
    defaultProvider: string;
    routines?: Routine[];
    mailbox?: Mailbox | null;
}

const str = (value: number | string | null | undefined) => (value === null || value === undefined ? '' : String(value));

interface SectionLink {
    id: string;
    label: string;
    icon: LucideIcon;
    count?: number;
}

const mailboxStatus: Record<string, { label: string; tone: Tone }> = {
    provisioning: { label: 'Em preparação', tone: 'warning' },
    active: { label: 'Activa', tone: 'success' },
    error: { label: 'Com erro', tone: 'danger' },
    disabled: { label: 'Desactivada', tone: 'idle' },
};

/** The section whose heading was scrolled past last, for the sticky section nav. */
function useActiveSection(ids: string[]) {
    const [active, setActive] = useState(ids[0]);
    const key = ids.join(',');

    useEffect(() => {
        let frame = 0;
        const update = () => {
            frame = 0;
            const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
            let current = ids[0];

            for (const id of ids) {
                const element = document.getElementById(id);

                if (element && element.getBoundingClientRect().top <= 120) {
                    current = id;
                }
            }

            setActive(atBottom ? ids[ids.length - 1] : current);
        };
        const onScroll = () => {
            if (!frame) {
                frame = requestAnimationFrame(update);
            }
        };

        update();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            cancelAnimationFrame(frame);
        };
    }, [key]);

    return active;
}

function SectionNav({ sections, active, note }: { sections: SectionLink[]; active: string; note?: string }) {
    return (
        <nav className="hidden lg:block">
            <div className="sticky top-20 flex flex-col gap-0.5">
                <p className="mb-2 px-2.5 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">Secções</p>
                {sections.map((section) => (
                    <a
                        key={section.id}
                        href={`#${section.id}`}
                        className={cn(
                            'flex h-8 items-center gap-2 rounded-lg px-2.5 text-sm text-muted-foreground transition-colors hover:bg-accent/60 hover:text-foreground',
                            active === section.id && 'bg-accent font-medium text-foreground',
                        )}
                    >
                        <section.icon className="size-4 shrink-0" />
                        <span className="truncate">{section.label}</span>
                        {section.count !== undefined && <span className="ml-auto font-mono text-[11px] tabular-nums">{section.count}</span>}
                    </a>
                ))}
                {note && <p className="mt-3 px-2.5 text-xs text-muted-foreground">{note}</p>}
            </div>
        </nav>
    );
}

/** A bordered block of the builder: heading strip, body, optional footer. */
function FormSection({
    id,
    title,
    description,
    action,
    footer,
    children,
}: {
    id: string;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-20 overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground">{description}</p>}
                </div>
                {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
            </header>
            <div className="p-5">{children}</div>
            {footer && <div className="flex items-center justify-end gap-2 border-t bg-muted/30 px-5 py-3">{footer}</div>}
        </section>
    );
}

function SourcePill({ source }: { source: 'local' | 'mcp' }) {
    return (
        <span className="inline-flex h-5 items-center rounded-full border px-2 font-mono text-[11px] text-muted-foreground">
            {source === 'mcp' ? 'ERP' : 'local'}
        </span>
    );
}

export default function AgentForm({
    tenant,
    agent,
    departments,
    users,
    capabilities,
    levels,
    statuses,
    providers,
    defaultProvider,
    routines = [],
    mailbox,
}: Props) {
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
        capabilities: agent?.capabilities ?? [],
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
    const visibleCapabilities = useMemo(
        () => capabilities.filter((capability) => `${capability.key} ${capability.name}`.toLowerCase().includes(filter.toLowerCase())),
        [capabilities, filter],
    );

    const toggleCapability = (id: number, on: boolean) =>
        form.setData('capabilities', on ? [...form.data.capabilities, id] : form.data.capabilities.filter((capabilityId) => capabilityId !== id));

    const sections: SectionLink[] = [
        { id: 'identidade', label: 'Identidade', icon: Bot },
        { id: 'personalidade', label: 'Personalidade e instruções', icon: Brain },
        { id: 'modelo', label: 'Autonomia e modelo', icon: Cpu },
        { id: 'capabilities', label: 'Capacidades', icon: Puzzle, count: form.data.capabilities.length },
        ...(agent
            ? [
                  { id: 'rotinas', label: 'Rotinas', icon: CalendarClock, count: routines.length },
                  { id: 'caixa', label: 'Caixa de correio', icon: Mail },
              ]
            : []),
    ];
    const active = useActiveSection(sections.map((section) => section.id));
    const statusLabel = statuses.find((status) => status.value === form.data.status)?.label ?? form.data.status;

    return (
        <AdminLayout
            title={agent ? agent.name : 'Novo agente'}
            breadcrumbs={[
                { label: 'Organizações', href: '/tenants' },
                { label: tenant.name, href: `/tenants/${tenant.id}` },
                { label: agent ? agent.name : 'Novo agente' },
            ]}
            wide
        >
            <PageHeader
                title={
                    <span className="flex items-center gap-3">
                        <Monogram name={form.data.name || 'Novo agente'} agent className="size-8 text-xs" />
                        {agent ? agent.name : 'Novo agente'}
                        {agent && <StatusBadge tone={agentTone(agent.status)}>{statusLabel}</StatusBadge>}
                    </span>
                }
                description="A definição do agente: quem é, como fala, o que faz, com que modelo, que capacidades usa e até onde pode agir sozinho."
                actions={agent && <AutonomyBadge level={agent.autonomy_level} withLabel />}
            />

            <div className="grid gap-8 lg:grid-cols-[13rem_minmax(0,1fr)]">
                <SectionNav
                    sections={sections}
                    active={active}
                    note={agent ? undefined : 'Rotinas e caixa de correio ficam disponíveis depois de criar o agente.'}
                />

                <div className="flex min-w-0 flex-col gap-6">
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <FormSection id="identidade" title="Identidade" description="Quem é o agente e a quem responde.">
                            <div className="grid gap-4">
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field id="name" label="Nome" error={errors.name}>
                                        <Input id="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
                                    </Field>
                                    <Field id="key" label="Chave" error={errors.key} hint="Identificador único: triagem, chief-of-staff…">
                                        <Input
                                            id="key"
                                            className="font-mono"
                                            value={form.data.key}
                                            onChange={(e) => form.setData('key', e.target.value)}
                                            required
                                        />
                                    </Field>
                                </div>
                                <Field id="title" label="Função" error={errors.title}>
                                    <Input
                                        id="title"
                                        placeholder="Agente Comercial e de Triagem"
                                        value={form.data.title}
                                        onChange={(e) => form.setData('title', e.target.value)}
                                    />
                                </Field>
                                <Field id="description" label="Descrição" error={errors.description}>
                                    <Textarea
                                        id="description"
                                        rows={2}
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                    />
                                </Field>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    <Field id="department_id" label="Departamento" error={errors.department_id}>
                                        <NativeSelect
                                            id="department_id"
                                            value={form.data.department_id}
                                            onChange={(e) => form.setData('department_id', e.target.value)}
                                        >
                                            <option value="">—</option>
                                            {departments.map((department) => (
                                                <option key={department.id} value={department.id}>
                                                    {department.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field id="reports_to_user_id" label="Responde a" error={errors.reports_to_user_id}>
                                        <NativeSelect
                                            id="reports_to_user_id"
                                            value={form.data.reports_to_user_id}
                                            onChange={(e) => form.setData('reports_to_user_id', e.target.value)}
                                        >
                                            <option value="">—</option>
                                            {users.map((user) => (
                                                <option key={user.id} value={user.id}>
                                                    {user.name}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                </div>
                            </div>
                        </FormSection>

                        <FormSection
                            id="personalidade"
                            title="Personalidade e instruções"
                            description="Entram no prompt de sistema, depois da identidade e antes das regras da plataforma, que não se podem sobrepor."
                        >
                            <div className="grid gap-4">
                                <Field id="personality" label="Personalidade" error={errors.personality}>
                                    <Textarea
                                        id="personality"
                                        rows={3}
                                        value={form.data.personality}
                                        onChange={(e) => form.setData('personality', e.target.value)}
                                    />
                                </Field>
                                <Field id="instructions" label="Instruções" error={errors.instructions}>
                                    <Textarea
                                        id="instructions"
                                        rows={14}
                                        className="font-mono text-xs leading-relaxed"
                                        value={form.data.instructions}
                                        onChange={(e) => form.setData('instructions', e.target.value)}
                                    />
                                </Field>
                            </div>
                        </FormSection>

                        <FormSection
                            id="modelo"
                            title="Autonomia e modelo"
                            description="O tecto absoluto (pagamentos, facturas, contratos, pessoas, permissões) pede sempre aprovação."
                        >
                            <div className="grid gap-6">
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
                                        <NativeSelect
                                            id="autonomy_level"
                                            value={form.data.autonomy_level}
                                            onChange={(e) => form.setData('autonomy_level', e.target.value)}
                                        >
                                            {levels.map((option) => (
                                                <option key={option.value} value={option.value}>
                                                    {option.code} · {option.label}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                        <div>
                                            <AutonomyBadge level={level} withLabel />
                                        </div>
                                    </Field>
                                </div>
                                <div className="grid gap-4 border-t pt-5 sm:grid-cols-2">
                                    <Field id="provider" label="Provedor" error={errors.provider}>
                                        <NativeSelect
                                            id="provider"
                                            value={form.data.provider}
                                            onChange={(e) => form.setData('provider', e.target.value)}
                                        >
                                            <option value="">Por omissão ({defaultProvider})</option>
                                            {providers.map((provider) => (
                                                <option key={provider} value={provider}>
                                                    {provider}
                                                </option>
                                            ))}
                                        </NativeSelect>
                                    </Field>
                                    <Field id="model" label="Modelo" error={errors.model} hint="Vazio: o modelo por omissão do provedor.">
                                        <Input
                                            id="model"
                                            className="font-mono"
                                            value={form.data.model}
                                            onChange={(e) => form.setData('model', e.target.value)}
                                        />
                                    </Field>
                                </div>
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <Field id="temperature" label="Temperatura" error={errors.temperature}>
                                        <Input
                                            id="temperature"
                                            type="number"
                                            step="0.1"
                                            min="0"
                                            max="2"
                                            className="font-mono"
                                            value={form.data.temperature}
                                            onChange={(e) => form.setData('temperature', e.target.value)}
                                        />
                                    </Field>
                                    <Field id="max_tokens" label="Máx. tokens" error={errors.max_tokens}>
                                        <Input
                                            id="max_tokens"
                                            type="number"
                                            min="1"
                                            className="font-mono"
                                            value={form.data.max_tokens}
                                            onChange={(e) => form.setData('max_tokens', e.target.value)}
                                        />
                                    </Field>
                                    <Field id="max_steps" label="Máx. passos" error={errors.max_steps}>
                                        <Input
                                            id="max_steps"
                                            type="number"
                                            min="1"
                                            max="50"
                                            className="font-mono"
                                            value={form.data.max_steps}
                                            onChange={(e) => form.setData('max_steps', e.target.value)}
                                        />
                                    </Field>
                                </div>
                            </div>
                        </FormSection>

                        <FormSection
                            id="capabilities"
                            title="Capacidades"
                            description={
                                <>
                                    <span className="font-mono text-foreground tabular-nums">{form.data.capabilities.length}</span> seleccionada(s). Sem
                                    aprovação, o agente só usa uma capacidade de escrita se o seu nível for igual ou superior ao risco dela.
                                </>
                            }
                            action={
                                <div className="relative w-full sm:w-56">
                                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input className="pl-8" placeholder="Filtrar…" value={filter} onChange={(e) => setFilter(e.target.value)} />
                                </div>
                            }
                        >
                            <InputErrorList errors={errors} prefix="capabilities" />
                            {visibleCapabilities.length === 0 ? (
                                <p className="rounded-lg border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                                    {capabilities.length === 0
                                        ? 'Esta organização ainda não tem capabilities. Actualize-as do ERP na página de capabilities.'
                                        : 'Nenhuma capacidade corresponde ao filtro.'}
                                </p>
                            ) : (
                                <ul className="grid max-h-[32rem] gap-px overflow-y-auto rounded-lg border bg-border sm:grid-cols-2">
                                    {visibleCapabilities.map((capability) => {
                                        const checked = form.data.capabilities.includes(capability.id);
                                        const gated = capability.ceiling || (capability.is_mutating && level < capability.risk);

                                        return (
                                            <li key={capability.id} className="bg-card">
                                                <label
                                                    className={cn(
                                                        'flex h-full cursor-pointer items-start gap-3 px-3 py-2.5 transition-colors hover:bg-accent/60',
                                                        checked && 'bg-primary/5',
                                                        !capability.is_available && 'cursor-not-allowed opacity-60',
                                                    )}
                                                >
                                                    <Checkbox
                                                        checked={checked}
                                                        disabled={!capability.is_available}
                                                        onCheckedChange={(on) => toggleCapability(capability.id, on === true)}
                                                        className="mt-0.5"
                                                    />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="flex flex-wrap items-center gap-1.5">
                                                            <span className="text-sm font-medium">{capability.name}</span>
                                                            <SourcePill source={capability.source} />
                                                            {capability.is_mutating ? (
                                                                <AutonomyBadge level={capability.risk} />
                                                            ) : (
                                                                <StatusBadge tone="idle" dot={false}>
                                                                    leitura
                                                                </StatusBadge>
                                                            )}
                                                            {capability.ceiling && (
                                                                <StatusBadge tone="danger" dot={false}>
                                                                    <Lock className="size-3" />
                                                                    tecto
                                                                </StatusBadge>
                                                            )}
                                                            {!capability.is_available && (
                                                                <StatusBadge tone="idle" dot={false}>
                                                                    indisponível
                                                                </StatusBadge>
                                                            )}
                                                        </span>
                                                        <span className="block truncate font-mono text-[11px] text-muted-foreground">
                                                            {capability.key}
                                                        </span>
                                                        {checked && gated && (
                                                            <span className="mt-0.5 flex items-center gap-1.5 text-xs text-status-warning">
                                                                <StatusDot tone="warning" pulse={false} className="size-1.5 [&>span]:size-1.5" />
                                                                Pede aprovação a este nível.
                                                            </span>
                                                        )}
                                                    </span>
                                                </label>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </FormSection>

                        <div className="sticky bottom-0 z-10 -mx-1 flex items-center justify-between gap-3 border-t bg-background/85 px-1 py-3 backdrop-blur">
                            <Button variant="ghost" asChild>
                                <Link href={`/tenants/${tenant.id}`}>Cancelar</Link>
                            </Button>
                            <div className="flex items-center gap-3">
                                {form.isDirty && <span className="hidden text-xs text-muted-foreground sm:inline">Há alterações por guardar.</span>}
                                <Button type="submit" disabled={form.processing}>
                                    {agent ? 'Guardar agente' : 'Criar agente'}
                                </Button>
                            </div>
                        </div>
                    </form>

                    {agent && <Routines base={`${base}/${agent.id}/routines`} routines={routines} />}
                    {agent && <MailboxForm action={`${base}/${agent.id}/mailbox`} mailbox={mailbox ?? null} />}
                </div>
            </div>
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
        <FormSection
            id="rotinas"
            title="Rotinas"
            description="Acções de rotina: um pedido que o agente executa num horário (expressão cron, hora de Maputo)."
        >
            <div className="grid gap-4">
                {routines.length === 0 ? (
                    <p className="text-sm text-muted-foreground">Sem rotinas. Adicione a primeira abaixo, por exemplo um briefing diário às 7h.</p>
                ) : (
                    <div className="divide-y rounded-lg border">
                        {routines.map((routine) => (
                            <RoutineRow key={routine.id} base={base} routine={routine} />
                        ))}
                    </div>
                )}

                <form onSubmit={submit} className="grid gap-3 rounded-lg border border-dashed p-4">
                    <p className="text-sm font-medium">Nova rotina</p>
                    <div className="grid gap-3 sm:grid-cols-[2fr_1fr]">
                        <Field id="routine-name" label="Nome" error={form.errors.name}>
                            <Input
                                id="routine-name"
                                placeholder="Briefing diário"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                            />
                        </Field>
                        <Field id="routine-schedule" label="Horário (cron)" error={form.errors.schedule}>
                            <Input
                                id="routine-schedule"
                                className="font-mono"
                                value={form.data.schedule}
                                onChange={(e) => form.setData('schedule', e.target.value)}
                            />
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
            </div>
        </FormSection>
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
            className="grid gap-3 p-4"
        >
            <div className="grid items-center gap-3 sm:grid-cols-[auto_2fr_1fr_auto]">
                <StatusDot tone={form.data.is_active ? 'success' : 'idle'} pulse={false} className="hidden sm:inline-flex" />
                <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-label="Nome" />
                <Input
                    className="font-mono"
                    value={form.data.schedule}
                    onChange={(e) => form.setData('schedule', e.target.value)}
                    aria-label="Horário"
                    aria-invalid={!!form.errors.schedule}
                />
                <label className="flex h-9 items-center gap-2 text-sm">
                    <Checkbox checked={form.data.is_active} onCheckedChange={(on) => form.setData('is_active', on === true)} />
                    Activa
                </label>
            </div>
            {form.errors.schedule && <p className="text-sm text-destructive">{form.errors.schedule}</p>}
            <Textarea rows={2} value={form.data.prompt} onChange={(e) => form.setData('prompt', e.target.value)} aria-label="Pedido" />
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs text-muted-foreground">
                    Última execução: <span title={dateTime(routine.last_run_at)}>{routine.last_run_at ? ago(routine.last_run_at) : 'nunca'}</span>
                </p>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="text-muted-foreground hover:text-status-danger"
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

    const text = (key: keyof typeof form.data, label: string, type = 'text', placeholder?: string, mono = false) => (
        <Field id={`mb-${key}`} label={label} error={form.errors[key]}>
            <Input
                id={`mb-${key}`}
                type={type}
                placeholder={placeholder}
                autoComplete="off"
                className={cn(mono && 'font-mono')}
                value={form.data[key]}
                onChange={(e) => form.setData(key, e.target.value)}
            />
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

    const current = mailbox ? mailboxStatus[mailbox.status] : undefined;

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.put(action, { preserveScroll: true, onSuccess: () => form.reset('imap_password', 'smtp_password') });
            }}
        >
            <FormSection
                id="caixa"
                title="Caixa de correio"
                description="A caixa própria do agente (Hostinger): IMAP para receber, SMTP para enviar. As palavras-passe ficam encriptadas."
                action={current && <StatusBadge tone={current.tone}>{current.label}</StatusBadge>}
                footer={
                    <Button type="submit" disabled={form.processing}>
                        Guardar caixa
                    </Button>
                }
            >
                <div className="grid gap-5">
                    {mailbox?.last_error && (
                        <div className="rounded-lg border border-status-danger/30 bg-status-danger/10 px-4 py-3 font-mono text-xs text-status-danger">
                            {mailbox.last_error}
                        </div>
                    )}
                    <div className="grid gap-4 sm:grid-cols-3">
                        {text('address', 'Endereço', 'email', 'triagem@agentes.exemplo.co.mz', true)}
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
                    <div className="grid gap-3 border-t pt-5">
                        <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Receber · IMAP</p>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                            {text('imap_host', 'Servidor IMAP', 'text', undefined, true)}
                            {text('imap_port', 'Porta', 'number', undefined, true)}
                            {encryption('imap_encryption')}
                            {text('imap_username', 'Utilizador')}
                            {text('imap_password', 'Palavra-passe', 'password', mailbox?.has_imap_password ? 'Guardada' : undefined)}
                        </div>
                    </div>
                    <div className="grid gap-3 border-t pt-5">
                        <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Enviar · SMTP</p>
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                            {text('smtp_host', 'Servidor SMTP', 'text', undefined, true)}
                            {text('smtp_port', 'Porta', 'number', undefined, true)}
                            {encryption('smtp_encryption')}
                            {text('smtp_username', 'Utilizador')}
                            {text('smtp_password', 'Palavra-passe', 'password', mailbox?.has_smtp_password ? 'Guardada' : undefined)}
                        </div>
                    </div>
                </div>
            </FormSection>
        </form>
    );
}
