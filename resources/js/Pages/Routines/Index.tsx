import { Head, Link, router, usePage } from '@inertiajs/react';
import { CalendarClock, MoreHorizontal, Pencil, Play, Plus, Trash2, Workflow as WorkflowIcon } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ListPanel } from '@/Components/Blocks';
import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { Button } from '@/Components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Switch } from '@/Components/ui/switch';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { defaultPick, describe, frequencies, type Frequency, fromCron, type SchedulePick, toCron, weekdays } from '@/lib/schedule';
import { cn } from '@/lib/utils';
import type { RunStatus } from '@/types';

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

const blank: FormState = { name: '', kind: 'agent', agent_id: '', workflow_id: '', prompt: '', owner_user_id: '', is_active: true, pick: defaultPick };

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

    // Links from elsewhere: ?routine=ID opens it, ?workflow=ID starts one for that flow.
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        const routine = routines.find((row) => String(row.id) === params.get('routine'));
        const workflow = workflows.find((row) => String(row.id) === params.get('workflow'));

        if (routine) {
            edit(routine);
        } else if (workflow) {
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
        const options = { preserveScroll: true, onSuccess: () => setOpen(false), onFinish: () => setSaving(false) };

        if (editing) {
            router.put(`/routines/${editing.id}`, payload, options);
        } else {
            router.post('/routines', payload, options);
        }
    };

    const toggle = (routine: Routine, active: boolean) => {
        router.put(
            `/routines/${routine.id}`,
            {
                ...fromRoutineToPayload(routine),
                is_active: active,
            },
            { preserveScroll: true },
        );
    };

    const runNow = (routine: Routine) => router.post(`/routines/${routine.id}/run`, {}, { preserveScroll: true });

    const setPick = (patch: Partial<SchedulePick>) => setForm((current) => ({ ...current, pick: { ...current.pick, ...patch } }));
    const agent = agents.find((row) => String(row.id) === form.agent_id);

    return (
        <AppLayout>
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

            <div className="mt-6">
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
                    <ListPanel>
                        {routines.map((routine) => (
                            <div key={routine.id} className="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center">
                                <div className="flex min-w-0 flex-1 items-start gap-3">
                                    {routine.kind === 'workflow' ? (
                                        <div className="flex size-8 shrink-0 items-center justify-center rounded-full border bg-muted">
                                            <WorkflowIcon className="size-4 text-muted-foreground" />
                                        </div>
                                    ) : (
                                        <AgentAvatar name={routine.agent?.name ?? '?'} className="size-8 shrink-0" />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <button type="button" onClick={() => edit(routine)} className="block max-w-full truncate text-left text-sm font-medium hover:underline">
                                            {routine.name}
                                        </button>
                                        <div className="text-xs text-muted-foreground">
                                            <span className="font-medium text-foreground/80">{routine.schedule_label}</span>
                                            {' · '}
                                            {routine.kind === 'workflow'
                                                ? `Fluxo «${routine.workflow?.name ?? '—'}»${routine.workflow?.agent ? ` com o ${routine.workflow.agent}` : ''}`
                                                : (routine.agent?.name ?? 'Sem agente')}
                                            {' · '}
                                            {routine.recipient ? `para ${routine.recipient}` : 'sem pessoa: só os admins'}
                                        </div>
                                        {routine.kind === 'agent' && routine.prompt && (
                                            <div className="mt-0.5 line-clamp-1 text-xs text-muted-foreground">{routine.prompt}</div>
                                        )}
                                        {((routine.kind === 'agent' && routine.agent && !routine.agent.active) ||
                                            (routine.kind === 'workflow' && routine.workflow && !routine.workflow.active)) && (
                                            <div className="mt-0.5 text-xs text-status-warning">
                                                {routine.kind === 'agent' ? 'O agente está suspenso: a rotina não corre.' : 'O fluxo não está activo: a rotina não corre.'}
                                            </div>
                                        )}
                                    </div>
                                </div>

                                <div className="flex shrink-0 items-center gap-3 pl-11 sm:pl-0">
                                    <div className="min-w-0 text-right text-xs text-muted-foreground" title={routine.last_run_at ? dateTime(routine.last_run_at) : undefined}>
                                        {routine.last_run_at ? (
                                            <span className="inline-flex items-center gap-2">
                                                {routine.last_status && <RunStatusBadge status={routine.last_status as RunStatus} label={routine.last_status_label ?? ''} />}
                                                {routine.last_url ? (
                                                    <Link href={routine.last_url} className="hover:underline">
                                                        {routine.last_task ?? 'correu'} {ago(routine.last_run_at)}
                                                    </Link>
                                                ) : (
                                                    <>correu {ago(routine.last_run_at)}</>
                                                )}
                                            </span>
                                        ) : (
                                            'nunca correu'
                                        )}
                                    </div>
                                    <Switch
                                        checked={routine.is_active}
                                        onCheckedChange={(checked) => toggle(routine, checked)}
                                        aria-label={routine.is_active ? 'Pausar rotina' : 'Activar rotina'}
                                    />
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="ghost" size="icon" aria-label="Mais acções">
                                                <MoreHorizontal />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem onSelect={() => edit(routine)}>
                                                <Pencil />
                                                Editar
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onSelect={() => runNow(routine)}>
                                                <Play />
                                                Correr agora
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem variant="destructive" onSelect={() => setRemoving(routine)}>
                                                <Trash2 />
                                                Apagar
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </div>
                        ))}
                    </ListPanel>
                )}
                <p className="mt-3 text-xs text-muted-foreground">
                    Horas de {timezone.replace('Africa/', '')}. Uma rotina que falhe a hora (o servidor estava desligado) corre uma vez quando ele volta, até 12 horas depois.
                    Aprovações, revisão do Chief of Staff e limites de custo aplicam-se como a qualquer outro trabalho; o custo aparece em Consumo de IA.
                </p>
            </div>

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
                                <NativeSelect id="agent_id" value={form.agent_id} onChange={(event) => setForm({ ...form, agent_id: event.target.value })}>
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
                                hint={workflows.length === 0 ? 'Ainda não há fluxos que começam por agenda: no editor do fluxo, escolha «Por agenda» em «Como começa».' : undefined}
                            >
                                <NativeSelect id="workflow_id" value={form.workflow_id} onChange={(event) => setForm({ ...form, workflow_id: event.target.value })}>
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
                                <Textarea id="prompt" rows={2} value={form.prompt} maxLength={10000} onChange={(event) => setForm({ ...form, prompt: event.target.value })} />
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
                                <NativeSelect aria-label="Minuto" value={form.pick.minute} onChange={(event) => setPick({ minute: Number(event.target.value) })}>
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
                                <Input aria-label="Hora" type="time" step={300} value={form.pick.time} onChange={(event) => setPick({ time: event.target.value })} />
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
                                            onClick={() => setPick({ days: on ? form.pick.days.filter((d) => d !== day.value) : [...form.pick.days, day.value] })}
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
                            <NativeSelect aria-label="Dia do mês" value={form.pick.day} onChange={(event) => setPick({ day: Number(event.target.value) })}>
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
                        <NativeSelect id="owner_user_id" value={form.owner_user_id} onChange={(event) => setForm({ ...form, owner_user_id: event.target.value })}>
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
                        router.delete(`/routines/${removing.id}`, { preserveScroll: true, onFinish: () => setRemoving(null) });
                    }
                }}
            />
        </AppLayout>
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
