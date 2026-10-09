import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    Clock,
    Coins,
    ExternalLink,
    MoreHorizontal,
    Pause,
    Pencil,
    Play,
    Plus,
    Trash2,
    TriangleAlert,
    Workflow as WorkflowIcon,
} from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ListPanel, MetricCard, Properties, Property, Section } from '@/Components/Blocks';
import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, dateTime, duration, plural, usd, usdPrecise } from '@/lib/format';
import { defaultPick, describe, frequencies, type Frequency, fromCron, type SchedulePick, toCron, weekdays } from '@/lib/schedule';
import { cn } from '@/lib/utils';

interface Routine {
    id: number;
    name: string;
    kind: 'agent' | 'workflow';
    agent: { id: number; name: string; active: boolean } | null;
    workflow: { id: number; name: string; active: boolean; agent: string | null } | null;
    prompt: string | null;
    schedule: string;
    schedule_label: string;
    owner_user_id: number | null;
    recipient: string | null;
    is_active: boolean;
    created_by: string | null;
    last_run_at: string | null;
    last_status: string | null;
    last_status_label: string | null;
    last_url: string | null;
    last_task: string | null;
    last_output: string | null;
    last_error: string | null;
    next_runs: string[];
    history: HistoryRow[];
    stats: { total: number; ok: number; failed: number; cost: number };
}

interface HistoryRow {
    kind: 'run' | 'task';
    at: string | null;
    status: string;
    status_label: string;
    tone: Tone;
    summary: string;
    cost: number | null;
    duration_ms: number | null;
    url: string;
}

interface Props {
    routines: Routine[];
    agents: { id: number; name: string; title: string | null; active: boolean }[];
    workflows: { id: number; name: string; active: boolean; status_label: string; agent: string | null }[];
    people: { id: number; name: string }[];
    timezone: string;
}

interface FormState {
    name: string;
    kind: 'agent' | 'workflow';
    agent_id: string;
    workflow_id: string;
    prompt: string;
    owner_user_id: string;
    is_active: boolean;
    pick: SchedulePick;
}

const blank: FormState = {
    name: '',
    kind: 'agent',
    agent_id: '',
    workflow_id: '',
    prompt: '',
    owner_user_id: '',
    is_active: true,
    pick: defaultPick,
};

const fromRoutine = (routine: Routine): FormState => ({
    name: routine.name,
    kind: routine.kind,
    agent_id: routine.agent ? String(routine.agent.id) : '',
    workflow_id: routine.workflow ? String(routine.workflow.id) : '',
    prompt: routine.prompt ?? '',
    owner_user_id: routine.owner_user_id ? String(routine.owner_user_id) : '',
    is_active: routine.is_active,
    pick: fromCron(routine.schedule),
});

/**
 * Rotinas (docs/DECISOES.md, "Rotinas e fluxos agendados"): what agents do on
 * their own at set times, or which flow starts, and who gets the result. The
 * admins always hear of it. Only admins see this page.
 */
export default function RoutinesIndex({ routines, agents, workflows, people, timezone }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [editing, setEditing] = useState<Routine | null>(null);
    const [open, setOpen] = useState(false);
    const [form, setForm] = useState<FormState>(blank);
    const [saving, setSaving] = useState(false);
    const [removing, setRemoving] = useState<Routine | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(() => {
        const id = Number(new URLSearchParams(typeof window === 'undefined' ? '' : window.location.search).get('routine'));

        return routines.some((row) => row.id === id) ? id : (routines[0]?.id ?? null);
    });

    const select = (id: number) => {
        setSelectedId(id);
        window.history.replaceState(window.history.state, '', `/settings/routines?routine=${id}`);
    };

    // A link from a flow (?workflow=ID) starts a routine for it.
    useEffect(() => {
        const workflow = workflows.find((row) => String(row.id) === new URLSearchParams(window.location.search).get('workflow'));

        if (workflow) {
            setEditing(null);
            setForm({ ...blank, kind: 'workflow', workflow_id: String(workflow.id), name: workflow.name });
            setOpen(true);
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const create = () => {
        setEditing(null);
        setForm(blank);
        setOpen(true);
    };

    const edit = (routine: Routine) => {
        setEditing(routine);
        setForm(fromRoutine(routine));
        setOpen(true);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        setSaving(true);
        const payload = {
            name: form.name,
            kind: form.kind,
            agent_id: form.kind === 'agent' && form.agent_id ? Number(form.agent_id) : null,
            workflow_id: form.kind === 'workflow' && form.workflow_id ? Number(form.workflow_id) : null,
            prompt: form.prompt || null,
            owner_user_id: form.owner_user_id ? Number(form.owner_user_id) : null,
            schedule: toCron(form.pick),
            is_active: form.is_active,
        };
        const options = { preserveScroll: true, preserveState: true, onSuccess: () => setOpen(false), onFinish: () => setSaving(false) };

        if (editing) {
            router.put(`/settings/routines/${editing.id}`, payload, options);
        } else {
            router.post('/settings/routines', payload, options);
        }
    };

    const toggle = (routine: Routine, active: boolean) => {
        router.put(
            `/settings/routines/${routine.id}`,
            {
                ...fromRoutineToPayload(routine),
                is_active: active,
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    const runNow = (routine: Routine) => router.post(`/settings/routines/${routine.id}/run`, {}, { preserveScroll: true, preserveState: true });

    const setPick = (patch: Partial<SchedulePick>) => setForm((current) => ({ ...current, pick: { ...current.pick, ...patch } }));
    const agent = agents.find((row) => String(row.id) === form.agent_id);

    const selected = routines.find((row) => row.id === selectedId) ?? routines[0];
    const active = routines.filter((row) => row.is_active);
    const failing = routines.filter((row) => row.stats.failed > 0);
    const cost = routines.reduce((sum, row) => sum + row.stats.cost, 0);
    const upcoming = active.flatMap((row) => row.next_runs.slice(0, 1).map((at) => ({ row, at }))).sort((a, b) => a.at.localeCompare(b.at))[0];

    return (
        <SettingsLayout>
            <Head title="Rotinas" />

            <PageHeader
                title="Rotinas"
                description="O que os agentes fazem sozinhos, a horas certas, e para quem vai o resultado. Os admins recebem sempre um aviso."
                actions={
                    <Button onClick={create}>
                        <Plus />
                        Nova rotina
                    </Button>
                }
            />

            {routines.length === 0 ? (
                <EmptyState
                    icon={CalendarClock}
                    title="Ainda não há rotinas"
                    description="Uma rotina pede trabalho a um agente, ou começa um fluxo, num horário: por exemplo, as margens por projecto às segundas às 08:00, para a directora financeira."
                    action={
                        <Button onClick={create}>
                            <Plus />
                            Criar a primeira rotina
                        </Button>
                    }
                />
            ) : (
                <>
                    <div className="grid divide-y rounded-xl border bg-card sm:grid-cols-2 sm:divide-y-0 xl:grid-cols-4 xl:divide-x">
                        <MetricCard
                            icon={CalendarClock}
                            value={active.length}
                            label="Activas"
                            description={`${routines.length - active.length} em pausa`}
                        />
                        <MetricCard
                            icon={Clock}
                            value={upcoming ? relative(upcoming.at) : '—'}
                            label="Próxima"
                            description={upcoming ? `${upcoming.row.name}, ${dateTime(upcoming.at)}` : 'Nenhuma rotina activa'}
                        />
                        <MetricCard
                            icon={TriangleAlert}
                            value={failing.length}
                            label="Com falhas"
                            description="nos últimos 30 dias"
                            tone={failing.length > 0 ? 'danger' : undefined}
                        />
                        <MetricCard icon={Coins} value={usd(cost)} label="Custo" description="das rotinas nos últimos 30 dias" />
                    </div>

                    <div className="grid gap-5 lg:grid-cols-[18rem_minmax(0,1fr)]">
                        <Section title={`Rotinas · ${routines.length}`}>
                            <ListPanel>
                                {routines.map((routine) => (
                                    <button
                                        key={routine.id}
                                        type="button"
                                        onClick={() => select(routine.id)}
                                        className={cn(
                                            'flex w-full items-start gap-3 px-3 py-2.5 text-left transition-colors hover:bg-accent/60',
                                            selected?.id === routine.id && 'bg-accent',
                                        )}
                                    >
                                        <RoutineIcon routine={routine} />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="truncate text-sm font-medium">{routine.name}</span>
                                                <StatusDot tone={routineTone(routine)} />
                                            </div>
                                            <div className="truncate text-xs text-muted-foreground">{routine.schedule_label}</div>
                                            <div className="truncate text-xs text-muted-foreground">
                                                {routine.is_active
                                                    ? routine.next_runs[0]
                                                        ? `Próxima ${relative(routine.next_runs[0])}`
                                                        : 'Sem próxima execução'
                                                    : 'Em pausa'}
                                                {routine.recipient ? ` · ${routine.recipient}` : ''}
                                            </div>
                                        </div>
                                    </button>
                                ))}
                            </ListPanel>
                        </Section>

                        {selected && (
                            <RoutineDetail
                                routine={selected}
                                onEdit={() => edit(selected)}
                                onRun={() => runNow(selected)}
                                onToggle={(value) => toggle(selected, value)}
                                onDelete={() => setRemoving(selected)}
                            />
                        )}
                    </div>

                    <p className="text-xs text-muted-foreground">
                        Horas de {timezone.replace('Africa/', '')}. Uma rotina que falhe a hora (o servidor estava desligado) corre uma vez quando ele
                        volta, até 12 horas depois. Aprovações, revisão do Chief of Staff e limites de custo aplicam-se como a qualquer outro
                        trabalho; o custo aparece em Consumo de IA.
                    </p>
                </>
            )}

            <FormDialog
                open={open}
                onOpenChange={setOpen}
                title={editing ? 'Editar rotina' : 'Nova rotina'}
                description="O agente faz o pedido no horário escolhido e responde na conversa da pessoa indicada. Os admins recebem um aviso com o resultado."
                submitLabel={editing ? 'Guardar' : 'Criar rotina'}
                processing={saving}
                onSubmit={submit}
                size="lg"
            >
                <div className="grid gap-4">
                    <Field id="name" label="Nome" error={errors.name}>
                        <Input
                            id="name"
                            value={form.name}
                            maxLength={255}
                            placeholder="Margens por projecto"
                            onChange={(event) => setForm({ ...form, name: event.target.value })}
                        />
                    </Field>

                    <Field id="kind" label="O que acontece" error={errors.kind}>
                        <div className="grid grid-cols-2 gap-2">
                            {(
                                [
                                    ['agent', 'Um agente faz um pedido'],
                                    ['workflow', 'Começa um fluxo de trabalho'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    onClick={() => setForm({ ...form, kind: value })}
                                    className={cn(
                                        'rounded-lg border px-3 py-2 text-left text-sm transition-colors hover:bg-accent/60',
                                        form.kind === value && 'border-primary bg-accent',
                                    )}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </Field>

                    {form.kind === 'agent' ? (
                        <>
                            <Field id="agent_id" label="Agente" error={errors.agent_id}>
                                <NativeSelect
                                    id="agent_id"
                                    value={form.agent_id}
                                    onChange={(event) => setForm({ ...form, agent_id: event.target.value })}
                                >
                                    <option value="">Escolha o agente</option>
                                    {agents.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                            {row.title ? ` · ${row.title}` : ''}
                                            {row.active ? '' : ' (suspenso)'}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field
                                id="prompt"
                                label="O que deve fazer"
                                error={errors.prompt}
                                hint={agent ? `O ${agent.name} usa só as ferramentas e o acesso que já tem.` : undefined}
                            >
                                <Textarea
                                    id="prompt"
                                    rows={4}
                                    value={form.prompt}
                                    maxLength={10000}
                                    placeholder="Lista as encomendas de compra com entrega em atraso, diz o fornecedor e quantos dias, e propõe a quem ligar primeiro."
                                    onChange={(event) => setForm({ ...form, prompt: event.target.value })}
                                />
                            </Field>
                        </>
                    ) : (
                        <>
                            <Field
                                id="workflow_id"
                                label="Fluxo"
                                error={errors.workflow_id}
                                hint={
                                    workflows.length === 0
                                        ? 'Ainda não há fluxos que começam por agenda: no editor do fluxo, escolha «Por agenda» em «Como começa».'
                                        : undefined
                                }
                            >
                                <NativeSelect
                                    id="workflow_id"
                                    value={form.workflow_id}
                                    onChange={(event) => setForm({ ...form, workflow_id: event.target.value })}
                                >
                                    <option value="">Escolha o fluxo</option>
                                    {workflows.map((row) => (
                                        <option key={row.id} value={row.id}>
                                            {row.name}
                                            {row.agent ? ` · ${row.agent}` : ''}
                                            {row.active ? '' : ` (${row.status_label.toLowerCase()})`}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field id="prompt" label="Nota para o agente (opcional)" error={errors.prompt}>
                                <Textarea
                                    id="prompt"
                                    rows={2}
                                    value={form.prompt}
                                    maxLength={10000}
                                    onChange={(event) => setForm({ ...form, prompt: event.target.value })}
                                />
                            </Field>
                        </>
                    )}

                    <Field id="frequency" label="Quando" error={errors.schedule} hint={describe(form.pick)}>
                        <div className="grid gap-2 sm:grid-cols-[1fr_8rem]">
                            <NativeSelect
                                id="frequency"
                                value={form.pick.frequency}
                                onChange={(event) => {
                                    const frequency = event.target.value as Frequency;
                                    setPick({ frequency, cron: frequency === 'custom' ? toCron(form.pick) : form.pick.cron });
                                }}
                            >
                                {frequencies.map((row) => (
                                    <option key={row.value} value={row.value}>
                                        {row.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            {form.pick.frequency === 'hourly' ? (
                                <NativeSelect
                                    aria-label="Minuto"
                                    value={form.pick.minute}
                                    onChange={(event) => setPick({ minute: Number(event.target.value) })}
                                >
                                    {[0, 15, 30, 45].map((minute) => (
                                        <option key={minute} value={minute}>
                                            aos {minute} min
                                        </option>
                                    ))}
                                </NativeSelect>
                            ) : form.pick.frequency === 'custom' ? (
                                <Input
                                    aria-label="Cron"
                                    className="font-mono"
                                    value={form.pick.cron}
                                    placeholder="0 8 * * 1-5"
                                    onChange={(event) => setPick({ cron: event.target.value })}
                                />
                            ) : (
                                <Input
                                    aria-label="Hora"
                                    type="time"
                                    step={300}
                                    value={form.pick.time}
                                    onChange={(event) => setPick({ time: event.target.value })}
                                />
                            )}
                        </div>
                        {form.pick.frequency === 'weekly' && (
                            <div className="flex flex-wrap gap-1.5">
                                {weekdays.map((day) => {
                                    const on = form.pick.days.includes(day.value);

                                    return (
                                        <button
                                            key={day.value}
                                            type="button"
                                            aria-pressed={on}
                                            onClick={() =>
                                                setPick({ days: on ? form.pick.days.filter((d) => d !== day.value) : [...form.pick.days, day.value] })
                                            }
                                            className={cn(
                                                'rounded-md border px-2.5 py-1 text-xs transition-colors hover:bg-accent/60',
                                                on && 'border-primary bg-primary text-primary-foreground hover:bg-primary/90',
                                            )}
                                        >
                                            {day.short}
                                        </button>
                                    );
                                })}
                            </div>
                        )}
                        {form.pick.frequency === 'monthly' && (
                            <NativeSelect
                                aria-label="Dia do mês"
                                value={form.pick.day}
                                onChange={(event) => setPick({ day: Number(event.target.value) })}
                            >
                                {Array.from({ length: 28 }, (_, i) => i + 1).map((day) => (
                                    <option key={day} value={day}>
                                        Dia {day}
                                    </option>
                                ))}
                            </NativeSelect>
                        )}
                    </Field>

                    <Field
                        id="owner_user_id"
                        label="Quem recebe o resultado"
                        error={errors.owner_user_id}
                        hint={
                            form.kind === 'agent'
                                ? 'A resposta aparece na conversa desta pessoa com o agente. Os admins recebem sempre um aviso.'
                                : 'A tarefa do fluxo fica com esta pessoa. Os admins recebem sempre um aviso.'
                        }
                    >
                        <NativeSelect
                            id="owner_user_id"
                            value={form.owner_user_id}
                            onChange={(event) => setForm({ ...form, owner_user_id: event.target.value })}
                        >
                            <option value="">
                                {form.kind === 'workflow'
                                    ? 'A pessoa de recurso do fluxo'
                                    : editing
                                      ? `Quem criou a rotina${editing.created_by ? ` (${editing.created_by})` : ''}`
                                      : 'Eu (quem cria a rotina)'}
                            </option>
                            {people.map((person) => (
                                <option key={person.id} value={person.id}>
                                    {person.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>

                    <label className="flex items-center gap-3 text-sm">
                        <Switch checked={form.is_active} onCheckedChange={(checked) => setForm({ ...form, is_active: checked })} />
                        Activa
                    </label>
                </div>
            </FormDialog>

            <ConfirmDialog
                open={removing !== null}
                onOpenChange={(value) => !value && setRemoving(null)}
                title={`Apagar «${removing?.name ?? ''}»?`}
                description="A rotina deixa de correr. As conversas e tarefas que já criou ficam."
                confirmLabel="Apagar"
                destructive
                onConfirm={() => {
                    if (removing) {
                        router.delete(`/settings/routines/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) });
                    }
                }}
            />
        </SettingsLayout>
    );
}

function fromRoutineToPayload(routine: Routine) {
    return {
        name: routine.name,
        kind: routine.kind,
        agent_id: routine.agent?.id ?? null,
        workflow_id: routine.workflow?.id ?? null,
        prompt: routine.prompt,
        owner_user_id: routine.owner_user_id,
        schedule: routine.schedule,
    };
}

const routineTone = (routine: Routine): Tone => {
    if (!routine.is_active) {
        return 'idle';
    }

    return routine.history[0]?.tone ?? 'success';
};

/** "em 25 min", "em 3 h", "em 2 dias"; a past time reads as "há …". */
function relative(iso: string): string {
    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60000);

    if (minutes < 0) {
        return ago(iso);
    }

    if (minutes < 60) {
        return `em ${Math.max(minutes, 1)} min`;
    }

    if (minutes < 60 * 24) {
        return `em ${Math.round(minutes / 60)} h`;
    }

    return `em ${plural(Math.round(minutes / (60 * 24)), 'dia', 'dias')}`;
}

function RoutineIcon({ routine, className }: { routine: Routine; className?: string }) {
    return routine.kind === 'workflow' ? (
        <div className={cn('flex size-8 shrink-0 items-center justify-center rounded-full border bg-muted', className)}>
            <WorkflowIcon className="size-4 text-muted-foreground" />
        </div>
    ) : (
        <AgentAvatar name={routine.agent?.name ?? '?'} className={cn('size-8 shrink-0', className)} />
    );
}

function RoutineDetail({
    routine,
    onEdit,
    onRun,
    onToggle,
    onDelete,
}: {
    routine: Routine;
    onEdit: () => void;
    onRun: () => void;
    onToggle: (active: boolean) => void;
    onDelete: () => void;
}) {
    const blocked =
        (routine.kind === 'agent' && routine.agent && !routine.agent.active) ||
        (routine.kind === 'workflow' && routine.workflow && !routine.workflow.active);
    const last = routine.history[0];

    return (
        <div className="flex min-w-0 flex-col gap-5">
            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 2xl:flex-row 2xl:items-start">
                <div className="flex min-w-0 flex-1 items-start gap-3">
                    <RoutineIcon routine={routine} className="size-10" />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-lg font-semibold tracking-tight">{routine.name}</h2>
                            <StatusBadge tone={routine.is_active ? 'success' : 'idle'}>{routine.is_active ? 'Activa' : 'Em pausa'}</StatusBadge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {routine.schedule_label} ·{' '}
                            {routine.kind === 'workflow'
                                ? `começa o fluxo «${routine.workflow?.name ?? '—'}»`
                                : `pedido ao ${routine.agent?.name ?? 'agente'}`}
                            {routine.recipient ? `, para ${routine.recipient}` : ''}
                        </p>
                        {blocked && (
                            <p className="mt-1 text-xs text-status-warning">
                                {routine.kind === 'agent'
                                    ? 'O agente está suspenso: a rotina não corre.'
                                    : 'O fluxo não está activo: a rotina não corre.'}
                            </p>
                        )}
                    </div>
                </div>
                <div className="flex shrink-0 flex-wrap items-center gap-2 pl-[3.25rem] 2xl:pl-0">
                    <Button variant="outline" size="sm" onClick={onRun}>
                        <Play />
                        Correr agora
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => onToggle(!routine.is_active)}>
                        {routine.is_active ? <Pause /> : <Play />}
                        {routine.is_active ? 'Pausar' : 'Activar'}
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" aria-label="Mais acções">
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem onSelect={onEdit}>
                                <Pencil />
                                Editar
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem variant="destructive" onSelect={onDelete}>
                                <Trash2 />
                                Apagar
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div className="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_19rem]">
                <div className="flex min-w-0 flex-col gap-5">
                    <Section
                        title={routine.kind === 'workflow' ? 'Nota para o agente' : 'O que o agente faz'}
                        action={
                            <button type="button" onClick={onEdit} className="text-xs text-primary hover:underline">
                                Editar
                            </button>
                        }
                    >
                        <div className="rounded-xl border bg-card px-4 py-3 text-sm whitespace-pre-wrap">
                            {routine.prompt || <span className="text-muted-foreground">Sem nota: o fluxo diz os passos.</span>}
                        </div>
                    </Section>

                    <Section
                        title="Último resultado"
                        action={
                            routine.last_url && (
                                <Link href={routine.last_url} className="inline-flex items-center gap-1 text-xs text-primary hover:underline">
                                    {routine.kind === 'workflow' ? 'Abrir a tarefa' : 'Abrir a conversa'}
                                    <ExternalLink className="size-3" />
                                </Link>
                            )
                        }
                    >
                        {last ? (
                            <div className="rounded-xl border bg-card px-4 py-3">
                                <div className="mb-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                    <StatusBadge tone={last.tone}>{last.status_label}</StatusBadge>
                                    {last.at && <span title={dateTime(last.at)}>{ago(last.at)}</span>}
                                    {last.duration_ms !== null && <span>· {duration(last.duration_ms)}</span>}
                                    {last.cost !== null && <span>· {usdPrecise(last.cost)}</span>}
                                </div>
                                <p className="line-clamp-[12] text-sm whitespace-pre-wrap">
                                    {routine.last_error ?? (routine.last_output || last.summary)}
                                </p>
                            </div>
                        ) : (
                            <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">
                                Ainda não correu. Use «Correr agora» para experimentar.
                            </p>
                        )}
                    </Section>

                    <Section title={`Histórico · ${routine.history.length}`}>
                        {routine.history.length === 0 ? (
                            <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">Sem execuções registadas.</p>
                        ) : (
                            <ListPanel>
                                {routine.history.map((row, index) => (
                                    <Link
                                        key={`${row.kind}-${index}`}
                                        href={row.url}
                                        className="flex items-center gap-3 px-4 py-2.5 text-sm transition-colors hover:bg-accent/60"
                                    >
                                        <StatusDot tone={row.tone} />
                                        <div className="min-w-0 flex-1">
                                            <div className="truncate">{row.summary}</div>
                                            <div className="text-xs text-muted-foreground">
                                                {row.status_label}
                                                {row.at ? ` · ${dateTime(row.at)}` : ''}
                                            </div>
                                        </div>
                                        <div className="hidden shrink-0 text-right text-xs text-muted-foreground tabular-nums sm:block">
                                            {row.duration_ms !== null && <div>{duration(row.duration_ms)}</div>}
                                            {row.cost !== null && <div>{usdPrecise(row.cost)}</div>}
                                        </div>
                                    </Link>
                                ))}
                            </ListPanel>
                        )}
                    </Section>
                </div>

                <div className="grid min-w-0 content-start gap-4 sm:grid-cols-2 2xl:grid-cols-1">
                    <Properties title="Detalhes">
                        <Property label="Quando">{routine.schedule_label}</Property>
                        <Property label="Próximas">
                            {routine.next_runs.length > 0 ? (
                                <span className="flex flex-col">
                                    {routine.next_runs.map((at) => (
                                        <span key={at}>
                                            {dateTime(at)} <span className="text-muted-foreground">({relative(at)})</span>
                                        </span>
                                    ))}
                                </span>
                            ) : (
                                <span className="text-muted-foreground">{routine.is_active ? 'Sem data' : 'Em pausa'}</span>
                            )}
                        </Property>
                        <Property label={routine.kind === 'workflow' ? 'Fluxo' : 'Agente'}>
                            {routine.kind === 'workflow' ? (
                                routine.workflow ? (
                                    <Link href={`/settings/workflows/${routine.workflow.id}/edit`} className="hover:underline">
                                        {routine.workflow.name}
                                    </Link>
                                ) : null
                            ) : routine.agent ? (
                                <Link href={`/agents/${routine.agent.id}`} className="hover:underline">
                                    {routine.agent.name}
                                </Link>
                            ) : null}
                        </Property>
                        {routine.kind === 'workflow' && <Property label="Agente">{routine.workflow?.agent}</Property>}
                        <Property label="Recebe">{routine.recipient ?? <span className="text-muted-foreground">Só os admins</span>}</Property>
                        <Property label="Admins">Recebem sempre um aviso</Property>
                        <Property label="Criada por">{routine.created_by}</Property>
                        <Property label="Cron">
                            <span className="font-mono text-xs">{routine.schedule}</span>
                        </Property>
                    </Properties>

                    <Properties title="Últimos 30 dias">
                        <Property label="Execuções">{routine.stats.total}</Property>
                        <Property label="Concluídas">{routine.stats.ok}</Property>
                        <Property label="Falhadas">
                            <span className={cn(routine.stats.failed > 0 && 'text-status-danger')}>{routine.stats.failed}</span>
                        </Property>
                        <Property label="Custo">{usdPrecise(routine.stats.cost)}</Property>
                    </Properties>
                </div>
            </div>
        </div>
    );
}
