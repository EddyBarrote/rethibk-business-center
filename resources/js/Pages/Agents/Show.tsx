import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Activity, BookOpen, Clock, ListTodo, Lock, MessagesSquare, Pause, Pencil, Play, Send, Wrench } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { EntityRow, ListPanel, Properties, Property, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { InputError } from '@/Components/InputError';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { agentTone, StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, usd } from '@/lib/format';
import type { AgentSummary, RunSummary, SharedProps } from '@/types';

interface Props {
    agent: AgentSummary & { personality: string | null; provider: string; model: string; assignees: { id: number; name: string }[] };
    capabilities: { key: string; name: string; is_mutating: boolean; risk: number; ceiling: boolean }[];
    skills: { id: number; key: string; name: string; description: string; scope: string; is_available: boolean }[];
    routines: { id: number; name: string; schedule: string; is_active: boolean; last_run_at: string | null }[];
    runs: RunSummary[];
    users: { id: number; name: string }[];
    can: { run: boolean; manage: boolean };
}

export default function AgentShow({ agent, capabilities, skills, routines, runs, users, can }: Props) {
    const { tenant, sidebar_agents } = usePage<SharedProps>().props;
    const form = useForm({ input: '' });
    const [reason, setReason] = useState('');
    const [assignees, setAssignees] = useState(agent.assignees.map((user) => user.id));
    const isRunning = sidebar_agents.some((item) => item.id === agent.id && item.running > 0);

    useLive(tenant ? `tenant.${tenant.id}.agents` : null, ['AgentRunStarted', 'AgentRunFinished'], () => router.reload({ only: ['runs', 'agent'] }), {
        only: ['runs'],
        poll: runs.some((run) => run.status === 'queued' || run.status === 'running'),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/agents/${agent.id}/runs`);
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
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/tasks?view=all&agent=${agent.id}`}>
                            <ListTodo />
                            Tarefas
                        </Link>
                    </Button>
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

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <Tabs defaultValue="overview" className="min-w-0 gap-6">
                    <TabsList variant="line" className="w-full justify-start border-b pb-1">
                        <TabsTrigger value="overview" className="flex-none">
                            Visão geral
                        </TabsTrigger>
                        <TabsTrigger value="runs" className="flex-none">
                            Execuções
                            <span className="font-mono text-xs text-muted-foreground tabular-nums">{runs.length}</span>
                        </TabsTrigger>
                        <TabsTrigger value="config" className="flex-none">
                            Configuração
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview" className="flex flex-col gap-8">
                        {can.run && (
                            <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border bg-card p-5">
                                <div>
                                    <h2 className="text-sm font-semibold">Pedir ao agente</h2>
                                    <p className="text-xs text-muted-foreground">O pedido entra na fila e pode acompanhá-lo ao vivo.</p>
                                </div>
                                <Textarea
                                    rows={3}
                                    placeholder="Ex.: Resume os leads novos desta semana."
                                    value={form.data.input}
                                    onChange={(e) => form.setData('input', e.target.value)}
                                />
                                <InputError message={form.errors.input} />
                                <div className="flex justify-end">
                                    <Button type="submit" disabled={form.processing || form.data.input.trim() === ''}>
                                        <Send />
                                        Executar
                                    </Button>
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
                                <Link href="/runs" className="text-muted-foreground hover:text-foreground">
                                    Ver todas
                                </Link>
                            }
                        >
                            <RunList runs={runs.slice(0, 5)} />
                        </Section>
                    </TabsContent>

                    <TabsContent value="runs">
                        <RunList runs={runs} />
                    </TabsContent>

                    <TabsContent value="config" className="flex flex-col gap-8">
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
                                            title={routine.name}
                                            subtitle={<span className="font-mono">{routine.schedule}</span>}
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
                                        A definição do agente é feita pela Rethink; aqui pode suspendê-lo e afectá-lo a pessoas.
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

                                    <div className="flex flex-col gap-2">
                                        <p className="text-sm font-medium">Pessoas afectas</p>
                                        <div className="grid max-h-48 gap-1.5 overflow-y-auto rounded-lg border p-3 sm:grid-cols-2">
                                            {users.map((user) => (
                                                <label key={user.id} className="flex items-center gap-2 text-sm">
                                                    <Checkbox
                                                        checked={assignees.includes(user.id)}
                                                        onCheckedChange={(on) =>
                                                            setAssignees(
                                                                on === true ? [...assignees, user.id] : assignees.filter((id) => id !== user.id),
                                                            )
                                                        }
                                                    />
                                                    {user.name}
                                                </label>
                                            ))}
                                        </div>
                                        <div className="flex justify-end">
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() =>
                                                    router.put(`/agents/${agent.id}/assignees`, { user_ids: assignees }, { preserveScroll: true })
                                                }
                                            >
                                                Guardar afectações
                                            </Button>
                                        </div>
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
                    <Property label="Afectos a">{agent.assignees.length > 0 ? agent.assignees.map((user) => user.name).join(', ') : null}</Property>
                    <Property label="Capacidades">
                        <span className="tabular-nums">{capabilities.length}</span>
                    </Property>
                    <Property label="Gasto recente">
                        <span className="font-mono tabular-nums" title={`Soma das últimas ${runs.length} execuções`}>
                            {usd(spent)}
                        </span>
                    </Property>
                </Properties>
            </div>
        </AppLayout>
    );
}

function RunList({ runs }: { runs: RunSummary[] }) {
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
                    href={`/runs/${run.id}`}
                    leading={<span className="w-12 font-mono text-xs text-muted-foreground tabular-nums">#{run.id}</span>}
                    title={run.input}
                    subtitle={run.trigger_label}
                    meta={
                        <>
                            <span title={dateTime(run.created_at)}>{ago(run.created_at)}</span>
                            <span className="w-16 text-right font-mono tabular-nums">{usd(run.cost_usd)}</span>
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
