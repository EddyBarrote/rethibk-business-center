import { Link, router, useForm } from '@inertiajs/react';
import { RotateCcw, Send } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { type Initiative, Initiatives } from '@/Components/home/Initiatives';
import { PersonAvatar } from '@/Components/PersonAvatar';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { ago, dateTime } from '@/lib/format';
import { colorOf } from '@/lib/projects';
import { cn } from '@/lib/utils';
import { TaskStatusIcon, type TaskSummary } from '@/Pages/Tasks/Index';

/*
 * Foco on Tarefas (docs/DECISOES.md, "Tarefas pelo que precisam de si", an
 * idea from OpenDuet): the person's tasks by what they need from them. Each
 * says the agent's next step; a question is answered right here, a failure
 * says why and can be tried again.
 */

export interface FocusTask extends TaskSummary {
    working: boolean;
    next: string | null;
    can_retry: boolean;
    can_reply: boolean;
}

export interface FocusData {
    sections: Record<'needs' | 'failed' | 'working' | 'review' | 'info' | 'done', FocusTask[]>;
    suggestions: Initiative[];
}

const sections: { key: keyof FocusData['sections']; label: string; empty?: string }[] = [
    { key: 'needs', label: 'Precisa de si', empty: 'Nada à sua espera.' },
    { key: 'failed', label: 'Não conseguiu' },
    { key: 'working', label: 'A trabalhar' },
    { key: 'review', label: 'Pronto para rever' },
    { key: 'info', label: 'Precisa de informação' },
    { key: 'done', label: 'Resolvido esta semana' },
];

export function TaskFocus({ focus }: { focus: FocusData }) {
    return (
        <div className="flex flex-col gap-8">
            {sections.map((section) => {
                const tasks = focus.sections[section.key];
                const showSuggestions = section.key === 'needs' && focus.suggestions.length > 0;

                if (tasks.length === 0 && !section.empty) {
                    return null;
                }

                return (
                    <section key={section.key} className="flex flex-col gap-2">
                        <h2 className="flex items-baseline gap-2 text-sm font-medium">
                            {section.label}
                            <span className="text-xs font-normal text-muted-foreground tabular-nums">{tasks.length}</span>
                        </h2>
                        {tasks.length === 0 ? (
                            <p className="text-sm text-muted-foreground">{section.empty}</p>
                        ) : (
                            <ul className="flex flex-col">
                                {tasks.map((task) => (
                                    <FocusRow key={task.id} task={task} failed={section.key === 'failed'} />
                                ))}
                            </ul>
                        )}
                        {showSuggestions && (
                            <div className="mt-3 flex flex-col gap-2">
                                <h3 className="text-xs font-medium text-muted-foreground">Sugestões dos agentes</h3>
                                <Initiatives initiatives={focus.suggestions} />
                            </div>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

function FocusRow({ task, failed }: { task: FocusTask; failed: boolean }) {
    const [replying, setReplying] = useState(false);
    const form = useForm({ body: '', mode: 'message' });
    const who = task.assignee ?? task.assignee_user;

    const reply = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/tasks/${task.id}/messages`, { preserveScroll: true, onSuccess: () => setReplying(false) });
    };

    return (
        <li className="flex items-start gap-3 rounded-md px-2 py-2 hover:bg-accent/40">
            <TaskStatusIcon status={task.status} label={task.status_label} className="mt-0.5 size-4 shrink-0" />
            <div className="min-w-0 flex-1">
                <Link href={`/tasks/${task.id}`} className="flex min-w-0 items-center gap-2 text-sm font-medium underline-offset-4 hover:underline">
                    <span className="truncate">{task.title}</span>
                    {task.project?.color && (
                        <span
                            className="size-2 shrink-0 rounded-full"
                            style={{ background: colorOf(task.project.color) }}
                            title={`Projecto: ${task.project.name}`}
                        />
                    )}
                </Link>
                {task.next && (
                    <p className={cn('line-clamp-2 text-xs', failed ? 'text-status-danger' : 'text-muted-foreground')}>
                        {task.working && <span className="mr-1 inline-block size-1.5 animate-pulse rounded-full bg-primary align-middle" />}
                        {task.next}
                    </p>
                )}
                {replying && (
                    <form onSubmit={reply} className="mt-2 flex gap-2">
                        <Input
                            autoFocus
                            value={form.data.body}
                            onChange={(event) => form.setData('body', event.target.value)}
                            placeholder="Responder ao agente…"
                            className="h-8"
                            aria-label={`Responder em «${task.title}»`}
                        />
                        <Button size="sm" type="submit" disabled={form.processing || form.data.body.trim() === ''}>
                            <Send />
                            Enviar
                        </Button>
                    </form>
                )}
            </div>
            <div className="flex shrink-0 items-center gap-2">
                {task.can_reply && !replying && (
                    <Button size="xs" variant="outline" onClick={() => setReplying(true)}>
                        Responder
                    </Button>
                )}
                {task.can_retry && (
                    <Button size="xs" variant="outline" onClick={() => router.post(`/tasks/${task.id}/retry`, {}, { preserveScroll: true })}>
                        <RotateCcw />
                        Tentar de novo
                    </Button>
                )}
                {who &&
                    (task.assignee ? (
                        <AgentAvatar name={who.name} className="size-5 rounded-md text-[8px]" />
                    ) : (
                        <PersonAvatar name={who.name} className="size-5 rounded-full text-[8px]" />
                    ))}
                <span
                    className="hidden w-16 text-right text-xs text-muted-foreground sm:block"
                    title={task.last_activity_at ? dateTime(task.last_activity_at) : undefined}
                >
                    {task.last_activity_at ? ago(task.last_activity_at) : ''}
                </span>
            </div>
        </li>
    );
}
