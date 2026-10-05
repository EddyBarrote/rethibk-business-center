import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowUpRight, CalendarDays, FolderKanban, Pencil, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
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

interface Project {
    id: number;
    name: string;
    description: string | null;
    status: 'planned' | 'active' | 'done' | 'cancelled';
    status_label: string;
    goal: { id: number; title: string } | null;
    lead: string | null;
    lead_is_agent: boolean;
    lead_user_id: number | null;
    lead_agent_id: number | null;
    target_date: string | null;
    tasks_total: number;
    tasks_done: number;
}

interface Props {
    projects: Project[];
    goals: { id: number; title: string }[];
    agents: { id: number; name: string }[];
    people: { id: number; name: string }[];
    statuses: Option[];
    can_manage: boolean;
}

const NONE = 'none';

const projectTone = (status: string): Tone =>
    (({ planned: 'idle', active: 'running', done: 'success', cancelled: 'idle' }) as Record<string, Tone>)[status] ?? 'idle';

export default function ProjectsIndex({ projects, goals, agents, people, statuses, can_manage }: Props) {
    const [editing, setEditing] = useState<Project | 'new' | null>(null);

    return (
        <AppLayout>
            <Head title="Projectos" />

            <PageHeader
                title="Projectos"
                description="Entre os objectivos e as tarefas: cada projecto serve um objectivo e junta as tarefas de pessoas e agentes."
                actions={
                    can_manage && (
                        <Button onClick={() => setEditing('new')}>
                            <Plus />
                            Novo projecto
                        </Button>
                    )
                }
            />

            {projects.length === 0 ? (
                <EmptyState
                    icon={FolderKanban}
                    title="Ainda sem projectos"
                    description={
                        can_manage
                            ? 'Crie um projecto (ex.: "Obra da ala norte"), ligue-o a um objectivo e junte-lhe tarefas.'
                            : 'Quando uma chefia criar projectos, aparecem aqui com o progresso das tarefas.'
                    }
                    action={
                        can_manage && (
                            <Button size="sm" onClick={() => setEditing('new')}>
                                <Plus />
                                Novo projecto
                            </Button>
                        )
                    }
                />
            ) : (
                <div className="divide-y overflow-hidden rounded-xl border bg-card">
                    {projects.map((project) => (
                        <ProjectRow key={project.id} project={project} onEdit={can_manage ? () => setEditing(project) : undefined} />
                    ))}
                </div>
            )}

            {editing !== null && (
                <ProjectDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    project={editing === 'new' ? null : editing}
                    goals={goals}
                    agents={agents}
                    people={people}
                    statuses={statuses}
                    onClose={() => setEditing(null)}
                />
            )}
        </AppLayout>
    );
}

function ProjectRow({ project, onEdit }: { project: Project; onEdit?: () => void }) {
    const percent = project.tasks_total > 0 ? Math.round((project.tasks_done / project.tasks_total) * 100) : 0;
    const tasksHref = `/tasks?view=all&project=${project.id}`;

    return (
        <div className="group flex items-center gap-3 px-4 py-3">
            <FolderKanban className={cn('size-4 shrink-0', project.status === 'active' ? 'text-primary' : 'text-muted-foreground')} />
            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-2">
                    <Link
                        href={tasksHref}
                        className={cn(
                            'truncate text-sm font-medium hover:underline',
                            project.status === 'cancelled' && 'text-muted-foreground line-through',
                        )}
                    >
                        {project.name}
                    </Link>
                    <StatusBadge tone={projectTone(project.status)} className="sm:hidden">
                        {project.status_label}
                    </StatusBadge>
                </div>
                <p className="line-clamp-1 text-xs text-muted-foreground">
                    {project.goal ? `Objectivo: ${project.goal.title}` : 'Sem objectivo'}
                    {project.description ? ` · ${project.description}` : ''}
                </p>
            </div>

            <div className="hidden shrink-0 items-center gap-4 text-xs text-muted-foreground md:flex">
                <span className="flex w-36 items-center gap-2 truncate">
                    {project.lead ? (
                        <>
                            {project.lead_is_agent ? (
                                <AgentAvatar name={project.lead} className="size-5 rounded-md text-[9px]" />
                            ) : (
                                <Monogram name={project.lead} className="size-5 rounded-md text-[9px]" />
                            )}
                            <span className="truncate text-foreground/80">{project.lead}</span>
                        </>
                    ) : (
                        'Sem responsável'
                    )}
                </span>
                <span className="flex w-24 items-center gap-1.5">
                    <CalendarDays className="size-3.5 shrink-0" />
                    {project.target_date ? date(project.target_date) : '—'}
                </span>
                <Link href={tasksHref} className="flex w-36 items-center gap-2 hover:text-foreground" title="Ver as tarefas deste projecto">
                    <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                        <span className="block h-full rounded-full bg-status-success" style={{ width: `${percent}%` }} />
                    </span>
                    <span className="w-10 text-right font-mono tabular-nums">
                        {project.tasks_done}/{project.tasks_total}
                    </span>
                </Link>
            </div>

            <div className="flex shrink-0 items-center gap-1">
                <StatusBadge tone={projectTone(project.status)} className="hidden sm:inline-flex">
                    {project.status_label}
                </StatusBadge>
                <Button variant="ghost" size="icon" className="size-7" asChild>
                    <Link href={tasksHref} aria-label="Ver tarefas">
                        <ArrowUpRight />
                    </Link>
                </Button>
                {onEdit && (
                    <Button variant="ghost" size="icon" className="size-7" onClick={onEdit} aria-label="Editar projecto">
                        <Pencil />
                    </Button>
                )}
            </div>
        </div>
    );
}

function ProjectDialog({
    project,
    goals,
    agents,
    people,
    statuses,
    onClose,
}: {
    project: Project | null;
    goals: Props['goals'];
    agents: Props['agents'];
    people: Props['people'];
    statuses: Option[];
    onClose: () => void;
}) {
    const form = useForm({
        name: project?.name ?? '',
        description: project?.description ?? '',
        status: project?.status ?? 'active',
        goal_id: project?.goal ? String(project.goal.id) : '',
        lead: project?.lead_agent_id ? `agent:${project.lead_agent_id}` : project?.lead_user_id ? `user:${project.lead_user_id}` : '',
        target_date: project?.target_date ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform(({ lead, ...data }) => ({
            ...data,
            description: data.description || null,
            goal_id: data.goal_id ? Number(data.goal_id) : null,
            lead_agent_id: lead.startsWith('agent:') ? Number(lead.slice(6)) : null,
            lead_user_id: lead.startsWith('user:') ? Number(lead.slice(5)) : null,
            target_date: data.target_date || null,
        }));
        const options = { preserveScroll: true, onSuccess: onClose };
        if (project) {
            form.put(`/projects/${project.id}`, options);
        } else {
            form.post('/projects', options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit} className="flex flex-col gap-5">
                    <DialogHeader>
                        <DialogTitle>{project ? 'Editar projecto' : 'Novo projecto'}</DialogTitle>
                        <DialogDescription>Um conjunto de trabalho com um responsável, ao serviço de um objectivo.</DialogDescription>
                    </DialogHeader>

                    <Field id="name" label="Nome" error={form.errors.name}>
                        <Input
                            id="name"
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="Ex.: Obra da ala norte"
                        />
                    </Field>

                    <Field id="description" label="Descrição" error={form.errors.description} hint="Opcional.">
                        <Textarea
                            id="description"
                            rows={3}
                            value={form.data.description}
                            onChange={(e) => form.setData('description', e.target.value)}
                        />
                    </Field>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field id="status" label="Estado" error={form.errors.status}>
                            <Select value={form.data.status} onValueChange={(value) => form.setData('status', value as Project['status'])}>
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

                    <Field id="goal_id" label="Objectivo" error={form.errors.goal_id}>
                        <Select value={form.data.goal_id || NONE} onValueChange={(value) => form.setData('goal_id', value === NONE ? '' : value)}>
                            <SelectTrigger id="goal_id" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Nenhum</SelectItem>
                                {goals.map((goal) => (
                                    <SelectItem key={goal.id} value={String(goal.id)}>
                                        {goal.title}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <Field
                        id="lead"
                        label="Responsável"
                        error={
                            (form.errors as Record<string, string | undefined>).lead_user_id ??
                            (form.errors as Record<string, string | undefined>).lead_agent_id
                        }
                    >
                        <Select value={form.data.lead || NONE} onValueChange={(value) => form.setData('lead', value === NONE ? '' : value)}>
                            <SelectTrigger id="lead" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={NONE}>Nenhum</SelectItem>
                                {people.map((person) => (
                                    <SelectItem key={`user:${person.id}`} value={`user:${person.id}`}>
                                        {person.name}
                                    </SelectItem>
                                ))}
                                {agents.map((agent) => (
                                    <SelectItem key={`agent:${agent.id}`} value={`agent:${agent.id}`}>
                                        {agent.name} (agente)
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>

                    <DialogFooter className="sm:justify-between">
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing || form.data.name.trim() === ''}>
                            {project ? 'Guardar' : 'Criar projecto'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
