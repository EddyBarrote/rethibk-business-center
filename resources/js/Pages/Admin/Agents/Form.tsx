import { router, useForm } from '@inertiajs/react';
import { CalendarClock, Mail, Plus, Trash2 } from 'lucide-react';
import { type FormEvent } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import AgentDefinitionForm, { type AgentData, type AgentFormOptions } from '@/Components/agents/AgentDefinitionForm';
import { FormSection, str } from '@/Components/agents/FormParts';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { ConfirmDialog } from '@/Components/Dialogs';
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

interface Props extends AgentFormOptions {
    tenant: { id: number; name: string };
    agent: AgentData | null;
    routines?: Routine[];
    mailbox?: Mailbox | null;
}

const mailboxStatus: Record<string, { label: string; tone: Tone }> = {
    provisioning: { label: 'Em preparação', tone: 'warning' },
    active: { label: 'Activa', tone: 'success' },
    error: { label: 'Com erro', tone: 'danger' },
    disabled: { label: 'Desactivada', tone: 'idle' },
};

export default function AgentForm({ tenant, agent, routines = [], mailbox, ...options }: Props) {
    const base = `/tenants/${tenant.id}/agents`;
    const statusLabel = options.statuses.find((status) => status.value === agent?.status)?.label ?? agent?.status;

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
                        <AgentAvatar name={agent?.name ?? 'Novo agente'} url={agent?.avatar_url} className="size-8 text-xs" />
                        {agent ? agent.name : 'Novo agente'}
                        {agent && <StatusBadge tone={agentTone(agent.status)}>{statusLabel}</StatusBadge>}
                    </span>
                }
                description="A definição do agente: quem é, como fala, o que faz, com que modelo, que capacidades e skills usa e até onde pode agir sozinho. Os administradores da organização também podem editá-la."
                actions={agent && <AutonomyBadge level={agent.autonomy_level} withLabel />}
            />

            <AgentDefinitionForm
                agent={agent}
                options={options}
                action={agent ? `${base}/${agent.id}` : base}
                cancelHref={`/tenants/${tenant.id}`}
                capabilitiesHref={`/tenants/${tenant.id}/capabilities`}
                navNote={agent ? undefined : 'Rotinas e caixa de correio ficam disponíveis depois de criar o agente.'}
                extraSections={
                    agent
                        ? [
                              { id: 'rotinas', label: 'Rotinas', icon: CalendarClock, count: routines.length },
                              { id: 'caixa', label: 'Caixa de correio', icon: Mail },
                          ]
                        : []
                }
                after={
                    agent && (
                        <>
                            <Routines base={`${base}/${agent.id}/routines`} routines={routines} />
                            <MailboxForm action={`${base}/${agent.id}/mailbox`} mailbox={mailbox ?? null} />
                        </>
                    )
                }
            />
        </AdminLayout>
    );
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
                    <ConfirmDialog
                        title="Apagar esta rotina?"
                        description="O agente deixa de a fazer a partir de agora. As execuções passadas ficam no histórico."
                        confirmLabel="Apagar rotina"
                        destructive
                        onConfirm={() => router.delete(`${base}/${routine.id}`, { preserveScroll: true })}
                        trigger={
                            <Button type="button" variant="ghost" size="sm" className="text-muted-foreground hover:text-status-danger">
                                <Trash2 />
                                Apagar
                            </Button>
                        }
                    />
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
