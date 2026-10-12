import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Activity, BookOpen, Brain, Clock, ExternalLink, ListTodo, Lock, MessagesSquare, Pause, Pencil, Play, Send, Wrench, Zap } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListPanel, Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { type MemoryEntry, MemoryEntries, type MemorySectionOption } from '@/Components/memory/MemoryEntries';
import { InputError } from '@/Components/InputError';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { agentTone, StatusBadge, StatusDot } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { NativeSelect } from '@/Components/ui/native-select';
import { Input } from '@/Components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import { useLive } from '@/hooks/useLive';
import { useToolNames } from '@/hooks/useToolNames';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, runTitle, usd } from '@/lib/format';
import { TaskRow, type TaskSummary } from '@/Pages/Tasks/Index';
import type { AgentSummary, RunSummary, SharedProps } from '@/types';

interface Props {
    agent: AgentSummary & {
        personality: string | null;
        provider: string;
        model: string;
        assignees: { id: number; name: string; level: 'chat' | 'work' }[];
    };
    capabilities: { key: string; name: string; is_mutating: boolean; risk: number; ceiling: boolean }[];
    skills: { id: number; key: string; name: string; description: string; scope: string; is_available: boolean }[];
    routines: { id: number; name: string; schedule: string; schedule_label: string; is_active: boolean; last_run_at: string | null }[];
    runs: RunSummary[];
    users: { id: number; name: string }[];
    can: { run: boolean; request_work: boolean; manage: boolean; manage_access: boolean; view_memory: boolean };
    memories: MemoryEntry[] | null;
    memory_sections: MemorySectionOption[];
    tasks: TaskSummary[];
    /** Other people's conversations with the agent, read-only, for whoever may read them all. */
    conversations: TeamConversation[] | null;
    now: { active: NowRun[]; last: NowRun | null };
}

interface TeamConversation {
    id: number;
    person: string | null;
    messages: number;
    status: string;
    status_label: string;
    last_activity_at: string | null;
}

/** A run on the agent's page with its conversation and the step it is on (AgentController::now). */
type NowRun = RunSummary & {
    started_at: string | null;
    task: { id: number | null; ref: string | null; title: string | null; kind: 'task' | 'chat'; visible: boolean } | null;
    step: { type: string; tool_name: string | null; at: string } | null;
};

export default function AgentShow({
    agent,
    capabilities,
    skills,
    routines,
    runs,
    users,
    memories,
    memory_sections,
    tasks,
    conversations,
    can,
    now,
}: Props) {
    const { tenant, auth } = usePage<SharedProps>().props;
    const form = useForm({ message: '', mode: 'message' as 'message' | 'action' });
    const [reason, setReason] = useState('');
    const [access, setAccess] = useState<Record<number, 'chat' | 'work'>>(Object.fromEntries(agent.assignees.map((user) => [user.id, user.level])));
    // The same rule as the sidebar (queued or running), from this page's own live data so the two never disagree.
    const isRunning = now.active.length > 0;

    useLive(
        tenant ? `tenant.${tenant.id}.agents` : null,
        ['AgentRunStarted', 'AgentRunFinished'],
        (_, payload: { agent_id?: number }) => {
            if (payload.agent_id === undefined || payload.agent_id === agent.id) {
                router.reload({ only: ['runs', 'now', 'sidebar_agents'] });
            }
        },
        { only: ['runs', 'now', 'sidebar_agents'], poll: isRunning },
    );

    // Asking from the agent's page goes into the person's one conversation with it (09.10.2026).
    const send = (mode: 'message' | 'action') => {
        form.transform((data) => ({ ...data, mode }));
        form.post(`/agents/${agent.id}/chat`);
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        send(can.request_work ? 'action' : 'message');
    };

    const setStatus = (status: 'active' | 'suspended') => router.put(`/agents/${agent.id}/status`, { status, reason }, { preserveScroll: true });

    const spent = runs.reduce((sum, run) => sum + run.cost_usd, 0);

    return (
        <AppLayout breadcrumbs={[{ label: 'Agentes', href: '/agents' }, { label: agent.name }]}>
            <Head title={agent.name} />

            <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-11 rounded-xl text-sm" />
                    <div className="min-w-0 space-y-0.5">
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="truncate text-xl font-semibold tracking-tight">{agent.name}</h1>
                            {isRunning ? (
                                <StatusBadge tone="running">A trabalhar</StatusBadge>
                            ) : (
                                <StatusBadge tone={agentTone(agent.status)}>{agent.status_label}</StatusBadge>
                            )}
                        </div>
                        {(agent.title || agent.description) && (
                            <p className="truncate text-sm text-muted-foreground">{agent.title ?? agent.description}</p>
                        )}
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <AutonomyBadge level={agent.autonomy_level} withLabel />
                    {can.manage && (
                        <Button variant="outline" size="sm" asChild>
                            <Link href={`/agents/${agent.id}/edit`}>
                                <Pencil />
                                Editar
                            </Link>
                        </Button>
                    )}
                    {can.run && <ChatAction agent={agent} />}
                </div>
            </div>

            {agent.status === 'suspended' && agent.suspended_reason && (
                <div className="rounded-xl border border-status-danger/30 bg-status-danger/10 px-4 py-3 text-sm text-status-danger">
                    Suspenso: {agent.suspended_reason}
                </div>
            )}

            <NowPanel active={now.active} last={now.last} />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <Tabs defaultValue="overview" className="min-w-0 gap-6">
                    <TabsList variant="line" className="scroll-fade w-full justify-start overflow-x-auto border-b pb-1">
                        <TabsTrigger value="overview" className="flex-none">
                            Visão geral
                        </TabsTrigger>
                        <TabsTrigger value="tasks" className="flex-none">
                            Tarefas
                            <span className="font-mono text-xs text-muted-foreground tabular-nums">{tasks.length}</span>
                        </TabsTrigger>
                        <TabsTrigger value="runs" className="flex-none">
                            Execuções
                            <span className="font-mono text-xs text-muted-foreground tabular-nums">{runs.length}</span>
                        </TabsTrigger>
                        {conversations !== null && (
                            <TabsTrigger value="conversations" className="flex-none">
                                Conversas da equipa
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">{conversations.length}</span>
                            </TabsTrigger>
                        )}
                        {memories !== null && (
                            <TabsTrigger value="memory" className="flex-none">
                                Memória
                                <span className="font-mono text-xs text-muted-foreground tabular-nums">{memories.length}</span>
                            </TabsTrigger>
                        )}
                        <TabsTrigger value="config" className="flex-none">
                            Configuração
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview" className="flex flex-col gap-8">
                        {can.run && (
                            <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border bg-card p-5">
                                <div>
                                    <h2 className="text-sm font-semibold">Pedir ao agente</h2>
                                    <p className="text-xs text-muted-foreground">
                                        Vai para a sua conversa com {agent.name}, onde acompanha a resposta ao vivo.
                                        {can.request_work && ' «Executar» pede-lhe que faça já e reporte.'}
                                    </p>
                                </div>
                                <Textarea
                                    rows={3}
                                    placeholder="Ex.: Resume os leads novos desta semana."
                                    value={form.data.message}
                                    onChange={(e) => form.setData('message', e.target.value)}
                                />
                                <InputError message={form.errors.message} />
                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant={can.request_work ? 'outline' : 'default'}
                                        disabled={form.processing || form.data.message.trim() === ''}
                                        onClick={() => send('message')}
                                    >
                                        <Send />
                                        Enviar
                                    </Button>
                                    {can.request_work && (
                                        <Button type="submit" disabled={form.processing || form.data.message.trim() === ''}>
                                            <Zap />
                                            Executar
                                        </Button>
                                    )}
                                </div>
                            </form>
                        )}

                        {agent.personality && (
                            <Section title="Personalidade">
                                <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">{agent.personality}</p>
                            </Section>
                        )}

                        <Section
                            title="Execuções recentes"
                            action={
                                <Link href="/settings/runs" className="text-muted-foreground hover:text-foreground">
                                    Ver todas
                                </Link>
                            }
                        >
                            <RunList runs={runs.slice(0, 5)} />
                        </Section>
                    </TabsContent>

                    <TabsContent value="tasks">
                        {tasks.length === 0 ? (
                            <EmptyState
                                icon={ListTodo}
                                title={`Ainda sem tarefas com ${agent.name}`}
                                description="O que pedir como trabalho na conversa passa a uma tarefa e aparece aqui."
                            />
                        ) : (
                            <ListPanel>
                                {tasks.map((task) => (
                                    <TaskRow key={task.id} task={task} compact />
                                ))}
                            </ListPanel>
                        )}
                    </TabsContent>

                    <TabsContent value="runs">
                        <RunList runs={runs} />
                    </TabsContent>

                    {conversations !== null && (
                        <TabsContent value="conversations" className="flex flex-col gap-3">
                            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                <Lock className="size-3.5" />
                                As conversas de outras pessoas com {agent.name}. Pode lê-las, não escrever nelas.
                            </p>
                            {conversations.length === 0 ? (
                                <EmptyState
                                    icon={MessagesSquare}
                                    title="Sem conversas de outras pessoas"
                                    description={`Ninguém mais conversou ainda com ${agent.name}.`}
                                />
                            ) : (
                                <ListPanel>
                                    {conversations.map((chat) => (
                                        <Link
                                            key={chat.id}
                                            href={`/tasks/${chat.id}`}
                                            className="flex items-center gap-3 px-4 py-2.5 transition-colors hover:bg-accent/50"
                                        >
                                            <Monogram name={chat.person ?? '?'} className="size-7 text-[10px]" />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-sm font-medium">{chat.person ?? 'Pessoa removida'}</span>
                                                <span className="block truncate text-xs text-muted-foreground">
                                                    {chat.messages} {chat.messages === 1 ? 'mensagem' : 'mensagens'} · {chat.status_label}
                                                </span>
                                            </span>
                                            {chat.last_activity_at && (
                                                <span className="shrink-0 text-xs text-muted-foreground" title={dateTime(chat.last_activity_at)}>
                                                    {ago(chat.last_activity_at)}
                                                </span>
                                            )}
                                        </Link>
                                    ))}
                                </ListPanel>
                            )}
                        </TabsContent>
                    )}

                    {memories !== null && (
                        <TabsContent value="memory">
                            <MemoryList memories={memories} sections={memory_sections} />
                        </TabsContent>
                    )}

                    <TabsContent value="config" className="flex flex-col gap-8">
                        {can.manage && (
                            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-center">
                                <p className="flex-1 text-sm text-muted-foreground">
                                    Nome, personalidade, instruções (prompt), modelo, autonomia, capacidades e skills mudam-se na edição do agente.
                                </p>
                                <Button size="sm" asChild>
                                    <Link href={`/agents/${agent.id}/edit`}>
                                        <Pencil />
                                        Editar agente
                                    </Link>
                                </Button>
                            </div>
                        )}
                        <Section title="Capacidades">
                            {capabilities.length === 0 ? (
                                <EmptyState
                                    icon={Wrench}
                                    title="Só a pesquisa na memória"
                                    description={
                                        can.manage
                                            ? 'Dê-lhe capacidades na página de edição do agente.'
                                            : 'Os administradores da organização dão capacidades a este agente.'
                                    }
                                />
                            ) : (
                                <ListPanel>
                                    {capabilities.map((capability) => (
                                        <EntityRow
                                            key={capability.key}
                                            title={capability.name}
                                            subtitle={<span className="font-mono">{capability.key}</span>}
                                            trailing={
                                                capability.ceiling ? (
                                                    <StatusBadge tone="danger" dot={false} title="Pede sempre aprovação, seja qual for a autonomia">
                                                        <Lock className="size-3" />
                                                        tecto
                                                    </StatusBadge>
                                                ) : capability.is_mutating ? (
                                                    <span title={agent.autonomy_level >= capability.risk ? 'Executa sozinho' : 'Pede aprovação'}>
                                                        <AutonomyBadge level={capability.risk} />
                                                    </span>
                                                ) : (
                                                    <StatusBadge tone="idle" dot={false}>
                                                        leitura
                                                    </StatusBadge>
                                                )
                                            }
                                        />
                                    ))}
                                </ListPanel>
                            )}
                        </Section>

                        <Section title="Skills">
                            {skills.length === 0 ? (
                                <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
                                    Sem skills: instruções da organização que o agente lê quando um trabalho as pede.
                                </p>
                            ) : (
                                <ListPanel>
                                    {skills.map((skill) => (
                                        <EntityRow
                                            key={skill.id}
                                            leading={<BookOpen className="size-4 text-muted-foreground" />}
                                            title={skill.name}
                                            subtitle={skill.description}
                                            trailing={
                                                <StatusBadge tone={skill.is_available ? 'idle' : 'warning'} dot={false}>
                                                    {skill.is_available ? (skill.scope === 'global' ? 'global' : 'da empresa') : 'desligada'}
                                                </StatusBadge>
                                            }
                                        />
                                    ))}
                                </ListPanel>
                            )}
                        </Section>

                        {routines.length > 0 && (
                            <Section title="Rotinas">
                                <ListPanel>
                                    {routines.map((routine) => (
                                        <EntityRow
                                            key={routine.id}
                                            leading={<Clock className="size-4 text-muted-foreground" />}
                                            href={
                                                auth.user?.permissions.includes('company.manage')
                                                    ? `/settings/routines?routine=${routine.id}`
                                                    : undefined
                                            }
                                            title={routine.name}
                                            subtitle={routine.schedule_label}
                                            meta={
                                                <span title={routine.last_run_at ? dateTime(routine.last_run_at) : undefined}>
                                                    {routine.last_run_at ? ago(routine.last_run_at) : 'nunca correu'}
                                                </span>
                                            }
                                            trailing={
                                                <StatusBadge tone={routine.is_active ? 'success' : 'idle'}>
                                                    {routine.is_active ? 'Activa' : 'Inactiva'}
                                                </StatusBadge>
                                            }
                                        />
                                    ))}
                                </ListPanel>
                            </Section>
                        )}

                        {can.manage && (
                            <Section title="Gestão">
                                <div className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                                    <p className="text-xs text-muted-foreground">
                                        Aqui pode suspendê-lo. Para mudar o prompt e o resto da definição, use «Editar».
                                    </p>

                                    {agent.status === 'active' ? (
                                        <div className="flex flex-col gap-2 sm:flex-row">
                                            <Input placeholder="Motivo (opcional)" value={reason} onChange={(e) => setReason(e.target.value)} />
                                            <Button variant="outline" onClick={() => setStatus('suspended')}>
                                                <Pause />
                                                Suspender agente
                                            </Button>
                                        </div>
                                    ) : agent.status === 'suspended' ? (
                                        <div>
                                            <Button onClick={() => setStatus('active')}>
                                                <Play />
                                                Reactivar agente
                                            </Button>
                                        </div>
                                    ) : null}
                                </div>
                            </Section>
                        )}

                        {can.manage_access && (
                            <Section title="Quem fala com este agente">
                                <div className="flex flex-col gap-3 rounded-xl border bg-card p-5">
                                    <p className="text-xs text-muted-foreground">
                                        Só falam com o agente as pessoas aqui, a pessoa a quem ele responde e quem tem no papel "Falar com todos os
                                        agentes". "Conversar" é perguntar; "Pedir trabalho" também cria tarefas e acções directas.
                                    </p>
                                    <div className="relative flex max-h-80 flex-col divide-y overflow-y-auto rounded-lg border">
                                        {users.map((user) => (
                                            <div key={user.id} className="flex items-center gap-3 px-3 py-2 text-sm">
                                                <span className="min-w-0 flex-1 truncate">{user.name}</span>
                                                <NativeSelect
                                                    aria-label={`Acesso de ${user.name}`}
                                                    className="w-40"
                                                    value={access[user.id] ?? 'none'}
                                                    onChange={(e) => {
                                                        const { [user.id]: _, ...rest } = access;
                                                        setAccess(
                                                            e.target.value === 'none'
                                                                ? rest
                                                                : { ...rest, [user.id]: e.target.value as 'chat' | 'work' },
                                                        );
                                                    }}
                                                >
                                                    <option value="none">Sem acesso</option>
                                                    <option value="chat">Conversar</option>
                                                    <option value="work">Pedir trabalho</option>
                                                </NativeSelect>
                                            </div>
                                        ))}
                                    </div>
                                    <div className="flex justify-end">
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.put(
                                                    `/agents/${agent.id}/assignees`,
                                                    {
                                                        access: Object.entries(access).map(([user_id, level]) => ({
                                                            user_id: Number(user_id),
                                                            level,
                                                        })),
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Guardar acessos
                                        </Button>
                                    </div>
                                </div>
                            </Section>
                        )}
                    </TabsContent>
                </Tabs>

                <Properties className="self-start">
                    <Property label="Estado">
                        {isRunning ? (
                            <StatusBadge tone="running">A trabalhar</StatusBadge>
                        ) : (
                            <StatusBadge tone={agentTone(agent.status)}>{agent.status_label}</StatusBadge>
                        )}
                    </Property>
                    <Property label="Autonomia">
                        <AutonomyBadge level={agent.autonomy_level} />
                    </Property>
                    <Property label="Departamento">{agent.department}</Property>
                    <Property label="Responde a">{agent.reports_to}</Property>
                    <Property label="Modelo">
                        <span className="font-mono text-xs">
                            {agent.provider} · {agent.model}
                        </span>
                    </Property>
                    <Property label="Chave">
                        <span className="font-mono text-xs">{agent.key}</span>
                    </Property>
                    <Property label="Falam com ele">
                        {agent.assignees.length > 0 ? agent.assignees.map((user) => user.name).join(', ') : null}
                    </Property>
                    <Property label="Capacidades">
                        <span className="tabular-nums">{capabilities.length}</span>
                    </Property>
                    <Property label="Gasto recente">
                        <span className="tabular-nums" title={`Soma das últimas ${runs.length} execuções`}>
                            {usd(spent)}
                        </span>
                    </Property>
                </Properties>
            </div>
        </AppLayout>
    );
}

/** Where a run is followed: its conversation with the run open in the side panel, or the run on its own. */
const runHref = (run: NowRun) => (run.task?.id ? `/tasks/${run.task.id}?execucao=${run.id}` : `/settings/runs/${run.id}`);

/** The step a run is on, in words. */
function stepLabel(run: NowRun, toolNames: Record<string, string> | undefined) {
    if (run.status === 'queued') {
        return 'Na fila';
    }

    switch (run.step?.type) {
        case 'tool_call':
            return `A usar ${(run.step.tool_name && toolNames?.[run.step.tool_name]) ?? run.step.tool_name ?? 'uma ferramenta'}`;
        case 'message':
            return 'A escrever a resposta';
        case 'approval':
            return 'À espera de aprovação';
        default:
            return 'A pensar';
    }
}

/** What the run is about: the conversation or task, else the request. */
function workTitle(run: NowRun, toolNames: Record<string, string> | undefined) {
    if (run.task && !run.task.visible) {
        return run.task.kind === 'chat' ? 'Numa conversa privada' : 'Numa tarefa a que não tem acesso';
    }

    if (run.task?.title) {
        return run.task.kind === 'chat' ? run.task.title : `${run.task.ref} · ${run.task.title}`;
    }

    return run.title ?? runTitle(run.input, toolNames);
}

/**
 * What the agent is doing right now, at the top of its page: each queued or
 * running piece of work with its conversation and current step, live, and a
 * link that opens the run inside the conversation. Idle, one line with its
 * last activity.
 */
function NowPanel({ active, last }: { active: NowRun[]; last: NowRun | null }) {
    const toolNames = useToolNames();

    if (active.length === 0) {
        return (
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-xl border bg-card px-4 py-3 text-sm text-muted-foreground">
                <StatusDot tone="idle" />
                <span className="text-foreground">Sem trabalho em curso.</span>
                {last ? (
                    <span className="min-w-0 truncate">
                        Última actividade {ago(last.finished_at ?? last.created_at)}:{' '}
                        {last.task && !last.task.visible ? (
                            workTitle(last, toolNames)
                        ) : (
                            <Link href={runHref(last)} className="text-foreground underline-offset-4 hover:underline">
                                {workTitle(last, toolNames)}
                            </Link>
                        )}
                    </span>
                ) : (
                    <span>Ainda não trabalhou.</span>
                )}
            </div>
        );
    }

    return (
        <section
            aria-label="A fazer agora"
            className="flex flex-col gap-2 rounded-xl border border-status-running/30 bg-[color-mix(in_oklch,var(--status-running)_6%,var(--card))] p-4"
        >
            <h2 className="flex items-center gap-2 text-xs font-medium tracking-wide text-status-running uppercase">
                <StatusDot tone="running" />A fazer agora
            </h2>
            <ul className="flex flex-col divide-y divide-status-running/15">
                {active.map((run) => (
                    <NowRow key={run.id} run={run} toolNames={toolNames} />
                ))}
            </ul>
        </section>
    );
}

function NowRow({ run, toolNames }: { run: NowRun; toolNames: Record<string, string> | undefined }) {
    const { tenant } = usePage<SharedProps>().props;
    const hidden = run.task !== null && !run.task.visible;

    // Each step the run takes refreshes the line, so the current step follows the run live.
    useLive(tenant && !hidden ? `tenant.${tenant.id}.run.${run.id}` : null, ['AgentRunStepAdded'], () => router.reload({ only: ['now'] }));

    return (
        <li className="flex flex-col gap-2 py-2 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:gap-4">
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{workTitle(run, toolNames)}</p>
                <p className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                    <span className="inline-flex items-center gap-1 text-foreground/80">
                        {run.step?.type === 'tool_call' && <Wrench className="size-3" />}
                        {stepLabel(run, toolNames)}
                    </span>
                    <span aria-hidden>·</span>
                    <span title={dateTime(run.started_at ?? run.created_at)}>
                        {run.status === 'queued' ? 'pedido' : 'começou'} {ago(run.started_at ?? run.created_at)}
                    </span>
                    {run.requested_by && (
                        <>
                            <span aria-hidden>·</span>
                            <span>a pedido de {run.requested_by}</span>
                        </>
                    )}
                </p>
            </div>
            {!hidden && (
                <Button variant="outline" size="sm" asChild className="self-start sm:self-auto">
                    <Link href={runHref(run)}>
                        <ExternalLink />
                        {run.task ? (run.task.kind === 'chat' ? 'Abrir conversa' : 'Abrir tarefa') : 'Ver execução'}
                    </Link>
                </Button>
            )}
        </li>
    );
}

function RunList({ runs }: { runs: RunSummary[] }) {
    const toolNames = useToolNames();
    if (runs.length === 0) {
        return (
            <EmptyState
                icon={Activity}
                title="Ainda não correu"
                description="Faça um pedido ao agente ou espere pela próxima rotina para ver a primeira execução."
            />
        );
    }

    return (
        <ListPanel>
            {runs.map((run) => (
                <EntityRow
                    key={run.id}
                    href={`/settings/runs/${run.id}`}
                    leading={<span className="w-12 font-mono text-xs text-muted-foreground tabular-nums">#{run.id}</span>}
                    title={run.title ?? runTitle(run.input, toolNames)}
                    subtitle={run.trigger_label}
                    meta={
                        <>
                            <span title={dateTime(run.created_at)}>{ago(run.created_at)}</span>
                            <span className="w-16 text-right tabular-nums">{usd(run.cost_usd)}</span>
                        </>
                    }
                    trailing={<RunStatusBadge status={run.status} label={run.status_label} />}
                />
            ))}
        </ListPanel>
    );
}

/** Open the person's one, continuous conversation with this agent (Grok-style). */
function ChatAction({ agent }: { agent: AgentSummary }) {
    return (
        <Button size="sm" asChild>
            <Link href={`/agents/${agent.id}/chat`}>
                <MessagesSquare />
                Conversar
            </Link>
        </Button>
    );
}

/** What the agent remembers of each person, for the administrators to correct (09.10.2026; entries since 12.10.2026). */
function MemoryList({ memories, sections }: { memories: MemoryEntry[]; sections: MemorySectionOption[] }) {
    const [person, setPerson] = useState<string>('all');

    if (memories.length === 0) {
        return (
            <EmptyState
                icon={Brain}
                title="Ainda sem memória"
                description="No fim de cada tarefa, a cada 30 mensagens de uma conversa e todas as noites, o agente arruma aqui o que aprendeu, em entradas com nome."
            />
        );
    }

    const people = [...new Map(memories.filter((m) => m.person).map((m) => [m.person!.id, m.person!.name])).entries()].sort((a, b) =>
        a[1].localeCompare(b[1]),
    );
    const shown = memories.filter((m) => person === 'all' || (person === 'agent' ? m.person === null : String(m.person?.id) === person));

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <p className="flex-1 text-xs text-muted-foreground">
                    Cada pessoa tem a sua memória com o agente, em entradas por secção, e vê-a em A minha conta › Memória. As entradas partilhadas
                    também vão para a base de conhecimento (pasta Memória dos agentes), e é assim que servem os outros. As outras nunca saem dali.
                </p>
                <NativeSelect aria-label="Memória de quem" className="sm:w-56" value={person} onChange={(e) => setPerson(e.target.value)}>
                    <option value="all">Todas as pessoas</option>
                    {people.map(([id, name]) => (
                        <option key={id} value={String(id)}>
                            {name}
                        </option>
                    ))}
                    {memories.some((m) => m.person === null) && <option value="agent">Só do agente (sem pessoa)</option>}
                </NativeSelect>
            </div>
            <MemoryEntries entries={shown} sections={sections} url={(e) => `/agents/${e.agent_id}/memories/${e.id}`} showPerson={person === 'all'} />
        </div>
    );
}
