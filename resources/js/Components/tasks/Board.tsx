import { Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { type DragEvent, type FormEvent, type ReactNode, useState } from 'react';

import { PersonAvatar } from '@/Components/PersonAvatar';
import { AgentAvatar } from '@/Components/AgentAvatar';
import { CollaboratorFace, partLabel } from '@/Components/tasks/Collaborators';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import { PriorityIcon, type TaskSummary } from '@/Pages/Tasks/Index';

/*
 * Quadro and Planear on Tarefas (docs/DECISOES.md, "Tarefas: quadro e
 * planear"): the same tasks as cards in lanes. A card dragged to another lane
 * changes its status (Quadro) or its day (Planear); a click opens the task.
 */

export interface BoardTask extends TaskSummary {
    working: boolean;
    collaborators: { id: number; kind: 'agent' | 'user'; name: string; avatar_url: string | null; role_label: string; status: string | null }[];
}

export interface BoardData {
    tasks: BoardTask[];
    week: { from: string; to: string; today: string };
    timezone: string;
}

const lanes: { key: string; label: string; statuses: string[]; drop: string }[] = [
    { key: 'todo', label: 'Por fazer', statuses: ['todo', 'blocked'], drop: 'todo' },
    { key: 'doing', label: 'Em curso', statuses: ['in_progress'], drop: 'in_progress' },
    { key: 'review', label: 'Por rever', statuses: ['waiting_human', 'in_review'], drop: 'in_review' },
    { key: 'done', label: 'Feito', statuses: ['done'], drop: 'done' },
];

const weekdays = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
const addDays = (day: string, n: number) => {
    const date = new Date(`${day}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + n);

    return date.toISOString().slice(0, 10);
};

function useDrag(onDrop: (taskId: number, lane: string) => void) {
    const [dragging, setDragging] = useState<number | null>(null);
    const [over, setOver] = useState<string | null>(null);

    return {
        dragging,
        over,
        card: (task: BoardTask) => ({
            draggable: true,
            onDragStart: (event: DragEvent) => {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', String(task.id));
                setDragging(task.id);
            },
            onDragEnd: () => {
                setDragging(null);
                setOver(null);
            },
        }),
        lane: (key: string) => ({
            onDragOver: (event: DragEvent) => {
                if (dragging !== null) {
                    event.preventDefault();
                    setOver(key);
                }
            },
            onDragLeave: () => setOver((current) => (current === key ? null : current)),
            onDrop: (event: DragEvent) => {
                event.preventDefault();
                if (dragging !== null) {
                    onDrop(dragging, key);
                }
                setDragging(null);
                setOver(null);
            },
        }),
    };
}

function Lane({
    title,
    count,
    active,
    extra,
    children,
    ...drop
}: {
    title: ReactNode;
    count: number;
    active: boolean;
    extra?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section
            {...drop}
            className={cn(
                'flex min-h-40 min-w-0 flex-col gap-2 rounded-xl bg-muted/40 p-2 transition-colors',
                active && 'bg-primary/10 ring-1 ring-primary/30',
            )}
        >
            <header className="flex items-center gap-2 px-1 text-xs font-medium text-muted-foreground">
                <span className="min-w-0 flex-1 truncate">{title}</span>
                <span className="tabular-nums">{count}</span>
                {extra}
            </header>
            {children}
        </section>
    );
}

function Card({ task, timezone, today, dragging, ...drag }: { task: BoardTask; timezone: string; today: string; dragging: boolean }) {
    const dueDay = task.due_at ? new Intl.DateTimeFormat('en-CA', { timeZone: timezone }).format(new Date(task.due_at)) : null;
    const late = dueDay !== null && dueDay < today && !['done', 'cancelled'].includes(task.status);
    const owner = task.assignee ?? task.assignee_user;

    return (
        <Link
            href={`/tasks/${task.id}`}
            {...drag}
            className={cn(
                'flex flex-col gap-2 rounded-lg bg-background p-2.5 text-sm shadow-xs ring-1 ring-border/60 transition hover:shadow-sm',
                dragging && 'opacity-40',
                task.status === 'done' && 'opacity-70',
            )}
        >
            <span className={cn('line-clamp-3 leading-snug font-medium', task.status === 'done' && 'line-through decoration-muted-foreground/50')}>
                {task.title}
            </span>
            <span className="flex flex-wrap items-center gap-x-2 gap-y-1 text-[11px] text-muted-foreground">
                {task.priority !== 'normal' && <PriorityIcon priority={task.priority} label={task.priority_label} />}
                {dueDay && (
                    <span className={cn('tabular-nums', late && 'font-medium text-status-danger')}>
                        {new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(
                            new Date(`${dueDay}T12:00:00Z`),
                        )}
                    </span>
                )}
                {task.working && <span className="text-status-running">a trabalhar</span>}
                <span className="ml-auto flex items-center -space-x-1">
                    {task.collaborators.slice(0, 3).map((c) => (
                        <span key={c.id} title={`${c.name} · ${c.role_label} · ${partLabel(c)}`} className="relative">
                            <CollaboratorFace collaborator={c} className="size-5 ring-2 ring-background" />
                            {(c.status === 'in_review' || c.status === 'waiting_human' || c.status === 'done') && (
                                <span
                                    className={cn(
                                        'absolute -right-0.5 -bottom-0.5 size-2 rounded-full ring-2 ring-background',
                                        c.status === 'done' ? 'bg-status-success' : 'bg-status-warning',
                                    )}
                                />
                            )}
                        </span>
                    ))}
                    {owner &&
                        (task.assignee ? (
                            <AgentAvatar name={owner.name} className="size-5 rounded-md text-[8px] ring-2 ring-background" />
                        ) : (
                            <span title={owner.name}>
                                <PersonAvatar name={owner.name} className="size-5 rounded-full text-[8px] ring-2 ring-background" />
                            </span>
                        ))}
                </span>
            </span>
        </Link>
    );
}

/** Quadro: the open tasks by status, and what was done in the last week. */
export function TaskBoard({ board }: { board: BoardData }) {
    const drag = useDrag((taskId, key) => {
        const task = board.tasks.find((t) => t.id === taskId);
        const lane = lanes.find((l) => l.key === key);
        if (task && lane && !lane.statuses.includes(task.status)) {
            router.patch(`/tasks/${taskId}`, { status: lane.drop }, { preserveScroll: true, preserveState: true });
        }
    });

    return (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            {lanes.map((lane) => {
                const items = board.tasks.filter((task) => lane.statuses.includes(task.status));

                return (
                    <Lane key={lane.key} title={lane.label} count={items.length} active={drag.over === lane.key} {...drag.lane(lane.key)}>
                        {items.map((task) => (
                            <Card
                                key={task.id}
                                task={task}
                                timezone={board.timezone}
                                today={board.week.today}
                                dragging={drag.dragging === task.id}
                                {...drag.card(task)}
                            />
                        ))}
                        {items.length === 0 && <p className="px-1 py-4 text-center text-xs text-muted-foreground/70">Arraste tarefas para aqui.</p>}
                    </Lane>
                );
            })}
        </div>
    );
}

/** Planear: the days of a week and the tasks without a date; a card dropped on a day gets that day. */
export function TaskPlanner({ board, onWeek }: { board: BoardData; onWeek: (week: string) => void }) {
    const days = Array.from({ length: 7 }, (_, index) => addDays(board.week.from, index));
    const dayOf = (task: BoardTask) =>
        task.due_at ? new Intl.DateTimeFormat('en-CA', { timeZone: board.timezone }).format(new Date(task.due_at)) : null;
    const [adding, setAdding] = useState<string | null>(null);
    const [title, setTitle] = useState('');

    const drag = useDrag((taskId, key) => {
        const task = board.tasks.find((t) => t.id === taskId);
        if (!task || key === 'atraso' || (key === 'sem' ? task.due_at === null : dayOf(task) === key)) {
            return;
        }
        router.patch(`/tasks/${taskId}`, { due_at: key === 'sem' ? null : `${key} 18:00` }, { preserveScroll: true, preserveState: true });
    });

    const add = (event: FormEvent) => {
        event.preventDefault();
        if (title.trim() === '' || adding === null) {
            return;
        }
        router.post(
            '/tasks',
            { kind: 'task', title: title.trim(), quick: true, due_at: adding === 'sem' ? null : `${adding} 18:00` },
            { preserveScroll: true, preserveState: true, onSuccess: () => setTitle('') },
        );
    };

    // In the current week, what is late waits on its own lane for a new day.
    const late = board.tasks.filter((task) => (dayOf(task) ?? '9') < board.week.from);
    const lanesOf = [
        ...(late.length > 0 ? [{ key: 'atraso', label: 'Em atraso' }] : []),
        { key: 'sem', label: 'Sem data' },
        ...days.map((day, index) => ({ key: day, label: `${weekdays[index]} ${Number(day.slice(8))}` })),
    ];
    const range = `${new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'short', timeZone: 'UTC' }).format(new Date(`${board.week.from}T12:00:00Z`))} – ${new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${board.week.to}T12:00:00Z`))}`;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex items-center gap-1">
                <Button variant="outline" size="sm" onClick={() => onWeek(board.week.today)}>
                    Esta semana
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label="Semana anterior"
                    onClick={() => onWeek(addDays(board.week.from, -7))}
                >
                    <ChevronLeft />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label="Semana seguinte"
                    onClick={() => onWeek(addDays(board.week.from, 7))}
                >
                    <ChevronRight />
                </Button>
                <span className="ml-1 text-sm font-semibold">{range}</span>
            </div>
            <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2">
                {lanesOf.map((lane) => {
                    const items =
                        lane.key === 'atraso'
                            ? late
                            : board.tasks.filter((task) => (lane.key === 'sem' ? task.due_at === null : dayOf(task) === lane.key));
                    const today = lane.key === board.week.today;

                    return (
                        <div key={lane.key} className="w-52 shrink-0 2xl:w-auto 2xl:flex-1">
                            <Lane
                                title={
                                    <span className={cn(today && 'text-primary', lane.key === 'atraso' && 'text-status-danger')}>{lane.label}</span>
                                }
                                count={items.length}
                                active={drag.over === lane.key}
                                extra={
                                    lane.key !== 'atraso' && (
                                        <button
                                            type="button"
                                            onClick={() => {
                                                setAdding(lane.key);
                                                setTitle('');
                                            }}
                                            className="inline-flex size-5 items-center justify-center rounded text-muted-foreground hover:bg-background hover:text-foreground"
                                            aria-label={`Nova tarefa: ${lane.label}`}
                                        >
                                            <Plus className="size-3.5" />
                                        </button>
                                    )
                                }
                                {...drag.lane(lane.key)}
                            >
                                {adding === lane.key && (
                                    <form onSubmit={add}>
                                        <input
                                            autoFocus
                                            value={title}
                                            onChange={(event) => setTitle(event.target.value)}
                                            onBlur={() => title.trim() === '' && setAdding(null)}
                                            onKeyDown={(event) => event.key === 'Escape' && setAdding(null)}
                                            placeholder="Nova tarefa…"
                                            className="w-full rounded-lg bg-background px-2.5 py-2 text-sm shadow-xs ring-1 ring-primary/40 outline-none"
                                        />
                                    </form>
                                )}
                                {items.map((task) => (
                                    <Card
                                        key={task.id}
                                        task={task}
                                        timezone={board.timezone}
                                        today={board.week.today}
                                        dragging={drag.dragging === task.id}
                                        {...drag.card(task)}
                                    />
                                ))}
                            </Lane>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
