import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowUpRight, CalendarDays, Pencil, Plus, Target } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { Monogram } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/Components/ui/select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { date } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option } from '@/types';

interface Goal {
    id: number;
    title: string;
    description: string | null;
    status: 'planned' | 'active' | 'achieved' | 'cancelled';
    status_label: string;
    parent_id: number | null;
    owner: string | null;
    owner_agent_id: number | null;
    target_date: string | null;
    tasks_total: number;
    tasks_done: number;
}

interface Props {
    goals: Goal[];
    agents: { id: number; name: string }[];
    statuses: Option[];
    can_manage: boolean;
}

const NONE = 'none';

const goalTone = (status: string): Tone =>
    (({ planned: 'idle', active: 'running', achieved: 'success', cancelled: 'idle' }) as Record<string, Tone>)[status] ?? 'idle';

/** Flatten the goal tree depth-first, keeping each goal's depth; orphans (parent not visible) become roots. */
function flatten(goals: Goal[]): { goal: Goal; depth: number }[] {
    const ids = new Set(goals.map((goal) => goal.id));
    const children = new Map<number | null, Goal[]>();
    goals.forEach((goal) => {
        const parent = goal.parent_id !== null && ids.has(goal.parent_id) ? goal.parent_id : null;
        children.set(parent, [...(children.get(parent) ?? []), goal]);
    });

    const out: { goal: Goal; depth: number }[] = [];
    const walk = (parent: number | null, depth: number, seen: Set<number>) => {
        (children.get(parent) ?? []).forEach((goal) => {
            if (seen.has(goal.id)) {
                return;
            }
            seen.add(goal.id);
            out.push({ goal, depth });
            walk(goal.id, depth + 1, seen);
        });
    };
    walk(null, 0, new Set());

    return out;
}

function descendants(goals: Goal[], id: number): Set<number> {
    const out = new Set<number>([id]);
    let grew = true;
    while (grew) {
        grew = false;
        goals.forEach((goal) => {
            if (goal.parent_id !== null && out.has(goal.parent_id) && !out.has(goal.id)) {
                out.add(goal.id);
                grew = true;
            }
        });
    }

    return out;
}

export default function GoalsIndex({ goals, agents, statuses, can_manage }: Props) {
    const [editing, setEditing] = useState<Goal | 'new' | null>(null);
    const rows = flatten(goals);

    return (
        <AppLayout>
            <Head title="Objectivos" />

            <PageHeader
                title="Objectivos"
                description="Para que serve o trabalho: cada tarefa pode apontar para um objectivo, e o progresso conta as tarefas feitas."
                actions={
                    can_manage && (
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            Novo objectivo
                        </Button>
                    )
                }
            />

            {rows.length === 0 ? (
                <EmptyState
                    icon={Target}
                    title="Ainda sem objectivos"
                    description={
                        can_manage
                            ? 'Crie o primeiro objectivo da empresa (ex.: "Fechar o trimestre sem atrasos") e associe-lhe tarefas dos agentes.'
                            : 'Quando um gestor definir os objectivos da empresa, aparecem aqui com o progresso das tarefas.'
                    }
                    action={
                        can_manage && (
                            <Button size="sm" onClick={() => setEditing('new')}>
                                <Plus />
                                Novo objectivo
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="divide-y overflow-hidden rounded-xl border bg-card">
                    {rows.map(({ goal, depth }) => (
                        <GoalRow key={goal.id} goal={goal} depth={depth} onEdit={can_manage ? () => setEditing(goal) : undefined} />
                    ))}
                </div>
            )}

            {editing !== null && (
                <GoalDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    goal={editing === 'new' ? null : editing}
                    goals={goals}
                    agents={agents}
                    statuses={statuses}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}

function GoalRow({ goal, depth, onEdit }: { goal: Goal; depth: number; onEdit?: () => void }) {
    const percent = goal.tasks_total > 0 ? Math.round((goal.tasks_done / goal.tasks_total) * 100) : 0;
    const muted = goal.status === 'cancelled';

    return (
        <div className="group flex items-center gap-3 px-4 py-3">
            <div className="flex min-w-0 flex-1 items-start gap-3" style={{ paddingLeft: `${Math.min(depth, 6) * 1.5}rem` }}>
                {depth > 0 ? (
                    <span className="mt-0.5 h-3 w-3 shrink-0 rounded-bl-md border-b border-l border-border" aria-hidden="true" />
                ) : (
                    <Target className={cn('mt-0.5 size-4 shrink-0', goal.status === 'active' ? 'text-primary' : 'text-muted-foreground')} />
                )}
                <div className="min-w-0 flex-1">
                    <div className="flex min-w-0 items-center gap-2">
                        <Link
                            href={`/tasks?view=all&goal=${goal.id}`}
                            className={cn('truncate text-sm font-medium hover:underline', muted && 'text-muted-foreground line-through')}
                        >
                            {goal.title}
                        </Link>
                        <StatusBadge tone={goalTone(goal.status)} className="sm:hidden">
                            {goal.status_label}
                        </StatusBadge>
                    </div>
                    {goal.description && <p className="line-clamp-1 text-xs text-muted-foreground">{goal.description}</p>}
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground md:hidden">
                        {goal.owner && <span>{goal.owner}</span>}
                        {goal.target_date && <span>até {date(goal.target_date)}</span>}
                        <span className="tabular-nums">
                            {goal.tasks_done}/{goal.tasks_total} tarefas
                        </span>
                    </div>
                </div>
            </div>

            <div className="hidden shrink-0 items-center gap-4 text-xs text-muted-foreground md:flex">
                <span className="flex w-36 items-center gap-2 truncate">
                    {goal.owner ? (
                        <>
                            <Monogram name={goal.owner} agent={goal.owner_agent_id !== null} className="size-5 rounded-md text-[9px]" />
                            <span className="truncate text-foreground/80">{goal.owner}</span>
                        </>
                    ) : (
                        'Sem responsável'
                    )}
                </span>
                <span className="flex w-24 items-center gap-1.5" title={goal.target_date ? `Data-alvo: ${date(goal.target_date)}` : undefined}>
                    <CalendarDays className="size-3.5 shrink-0" />
                    {goal.target_date ? date(goal.target_date) : '—'}
                </span>
                <Link
                    href={`/tasks?view=all&goal=${goal.id}`}
                    className="flex w-36 items-center gap-2 hover:text-foreground"
                    title="Ver as tarefas deste objectivo"
                >
                    <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                        <span className="block h-full rounded-full bg-status-success" style={{ width: `${percent}%` }} />
                    </span>
                    <span className="w-10 text-right font-mono tabular-nums">
                        {goal.tasks_done}/{goal.tasks_total}
                    </span>
                </Link>
            </div>

            <div className="flex shrink-0 items-center gap-1">
                <StatusBadge tone={goalTone(goal.status)} className="hidden sm:inline-flex">
                    {goal.status_label}
                </StatusBadge>
                <Button variant="ghost" size="icon" className="size-7" asChild>
                    <Link href={`/tasks?view=all&goal=${goal.id}`} aria-label="Ver tarefas">
                        <ArrowUpRight />
                    </Link>
                </Button>
                {onEdit && (
                    <Button variant="ghost" size="icon" className="size-7" onClick={onEdit} aria-label="Editar objectivo">
                        <Pencil />
                    </Button>
                )}
            </div>
        </div>
    );
}

function GoalDialog({
    goal,
    goals,
    agents,
    statuses,
    onClose,
}: {
    goal: Goal | null;
    goals: Goal[];
    agents: Props['agents'];
    statuses: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        title: goal?.title ?? '',
        description: goal?.description ?? '',
        status: goal?.status ?? 'active',
        owner_agent_id: goal?.owner_agent_id ? String(goal.owner_agent_id) : '',
        target_date: goal?.target_date ?? '',
        parent_id: goal?.parent_id ? String(goal.parent_id) : '',
    });
    const excluded = goal ? descendants(goals, goal.id) : new Set<number>();
    const parents = goals.filter((candidate) => !excluded.has(candidate.id));

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            description: data.description || null,
            owner_agent_id: data.owner_agent_id ? Number(data.owner_agent_id) : null,
            parent_id: data.parent_id ? Number(data.parent_id) : null,
            target_date: data.target_date || null,
        }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (goal) {
            form.put(`/goals/${goal.id}`, options);
        } else {
            form.post('/goals', options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{goal ? 'Editar objectivo' : 'Novo objectivo'}</DialogTitle>
                        <DialogDescription>Um resultado concreto; as tarefas dos agentes ligadas a ele mostram o progresso.</DialogDescription>
                    </DialogHeader>

                    <Field id="title" label="Título" error={form.errors.title}>
                        <Input
                            id="title"
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="Ex.: Reduzir o prazo médio de cobrança para 45 dias"
                        />
                    </Field>

                    <Field id="description" label="Descrição" error={form.errors.description} hint="Opcional. Porque importa e como se mede.">
                        <Textarea
                            id="description"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="status" label="Estado" error={form.errors.status}>
                            <Select value={form.data.status} onValueChange={(value) => form.setData('status', value as Goal['status'])}>
                                <SelectTrigger id="status" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {statuses.map((status) => (
                                        <SelectItem key={status.value} value={status.value}>
                                            {status.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field id="target_date" label="Data-alvo" error={form.errors.target_date}>
                            <Input
                                id="target_date"
                                type="date"
                                value={form.data.target_date}
                                onChange={(e) => form.setData('target_date', e.target.value)}
                            />
                        </Field>
                    </div>

                    <Field id="owner_agent_id" label="Agente responsável" error={form.errors.owner_agent_id}>
                        <Select
                            value={form.data.owner_agent_id || NONE}
                            onValueChange={(value) => form.setData('owner_agent_id', value === NONE ? '' : value)}
                        >
                            <SelectTrigger id="owner_agent_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Nenhum (fica consigo)</SelectItem>
                                {agents.map((agent) => (
                                    <SelectItem key={agent.id} value={String(agent.id)}>
                                        {agent.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field id="parent_id" label="Faz parte de" error={form.errors.parent_id}>
                        <Select value={form.data.parent_id || NONE} onValueChange={(value) => form.setData('parent_id', value === NONE ? '' : value)}>
                            <SelectTrigger id="parent_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Nenhum (objectivo de topo)</SelectItem>
                                {parents.map((parent) => (
                                    <SelectItem key={parent.id} value={String(parent.id)}>
                                        {parent.title}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <DialogFooter className="sm:justify-between">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing || form.data.title.trim() === ''}>
                            {goal ? 'Guardar' : 'Criar objectivo'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
