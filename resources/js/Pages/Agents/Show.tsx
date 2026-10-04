import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Clock, Lock, Pause, Play, Send } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { PageHeader } from '@/Components/PageHeader';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import { Textarea } from '@/Components/ui/textarea';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime, usd } from '@/lib/format';
import type { AgentSummary, RunSummary, SharedProps } from '@/types';

interface Props {
    agent: AgentSummary & { personality: string | null; provider: string; model: string; assignees: { id: number; name: string }[] };
    skills: { key: string; name: string; is_mutating: boolean; risk: number; ceiling: boolean }[];
    routines: { id: number; name: string; schedule: string; is_active: boolean; last_run_at: string | null }[];
    runs: RunSummary[];
    users: { id: number; name: string }[];
    can: { run: boolean; manage: boolean };
}

export default function AgentShow({ agent, skills, routines, runs, users, can }: Props) {
    const { tenant } = usePage<SharedProps>().props;
    const form = useForm({ input: '' });
    const [reason, setReason] = useState('');
    const [assignees, setAssignees] = useState(agent.assignees.map((user) => user.id));

    useLive(tenant ? `tenant.${tenant.id}.agents` : null, ['AgentRunStarted', 'AgentRunFinished'], () => router.reload({ only: ['runs', 'agent'] }), {
        only: ['runs'],
        poll: runs.some((run) => run.status === 'queued' || run.status === 'running'),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/agents/${agent.id}/runs`);
    };

    const setStatus = (status: 'active' | 'suspended') => router.put(`/agents/${agent.id}/status`, { status, reason }, { preserveScroll: true });

    return (
        <AppLayout>
            <Head title={agent.name} />
            <PageHeader
                title={agent.name}
                description={agent.title ?? agent.description ?? undefined}
                actions={
                    <div className="flex items-center gap-2">
                        {agent.status !== 'active' && <Badge variant="destructive">{agent.status_label}</Badge>}
                        <AutonomyBadge level={agent.autonomy_level} withLabel />
                    </div>
                }
            />

            {agent.status === 'suspended' && agent.suspended_reason && (
                <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">Suspenso: {agent.suspended_reason}</div>
            )}

            <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <div className="grid content-start gap-6">
                    {can.run && (
                        <Card>
                            <form onSubmit={submit}>
                                <CardHeader>
                                    <CardTitle>Pedir ao agente</CardTitle>
                                    <CardDescription>O pedido entra na fila e pode acompanhá-lo ao vivo.</CardDescription>
                                </CardHeader>
                                <CardContent className="mt-4">
                                    <Textarea rows={3} placeholder="Ex.: Resume os leads novos desta semana." value={form.data.input} onChange={(e) => form.setData('input', e.target.value)} />
                                    {form.errors.input && <p className="mt-1 text-sm text-destructive">{form.errors.input}</p>}
                                </CardContent>
                                <CardFooter className="mt-4 justify-end">
                                    <Button type="submit" disabled={form.processing || form.data.input.trim() === ''}>
                                        <Send />
                                        Executar
                                    </Button>
                                </CardFooter>
                            </form>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Execuções</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-4">
                            {runs.length === 0 ? (
                                <p className="text-sm text-muted-foreground">Ainda não correu.</p>
                            ) : (
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>#</TableHead>
                                            <TableHead>Pedido</TableHead>
                                            <TableHead>Origem</TableHead>
                                            <TableHead>Estado</TableHead>
                                            <TableHead className="text-right">Custo</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {runs.map((run) => (
                                            <TableRow key={run.id} className="cursor-pointer" onClick={() => router.visit(`/runs/${run.id}`)}>
                                                <TableCell className="tabular-nums">{run.id}</TableCell>
                                                <TableCell className="max-w-xs">
                                                    <p className="truncate">{run.input}</p>
                                                    <p className="text-xs text-muted-foreground">{dateTime(run.created_at)}</p>
                                                </TableCell>
                                                <TableCell>{run.trigger_label}</TableCell>
                                                <TableCell>
                                                    <RunStatusBadge status={run.status} label={run.status_label} />
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">{usd(run.cost_usd)}</TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid content-start gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Ficha</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-2 text-sm">
                            <Row label="Departamento" value={agent.department} />
                            <Row label="Responde a" value={agent.reports_to} />
                            <Row label="Modelo" value={`${agent.provider} · ${agent.model}`} />
                            {agent.personality && <p className="mt-2 text-muted-foreground">{agent.personality}</p>}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Skills</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-1.5">
                            {skills.length === 0 && <p className="text-sm text-muted-foreground">Só a pesquisa na memória.</p>}
                            {skills.map((skill) => (
                                <div key={skill.key} className="flex items-center justify-between gap-2 text-sm">
                                    <span className="truncate">{skill.name}</span>
                                    {skill.ceiling ? (
                                        <Badge variant="destructive">
                                            <Lock />
                                            tecto
                                        </Badge>
                                    ) : skill.is_mutating ? (
                                        <span title={agent.autonomy_level >= skill.risk ? 'Executa sozinho' : 'Pede aprovação'}>
                                            <AutonomyBadge level={skill.risk} />
                                        </span>
                                    ) : (
                                        <Badge variant="secondary">leitura</Badge>
                                    )}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    {routines.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Rotinas</CardTitle>
                            </CardHeader>
                            <CardContent className="mt-4 grid gap-2 text-sm">
                                {routines.map((routine) => (
                                    <div key={routine.id} className="flex items-start gap-2">
                                        <Clock className="mt-0.5 size-4 text-muted-foreground" />
                                        <div>
                                            <p className={routine.is_active ? undefined : 'line-through opacity-60'}>{routine.name}</p>
                                            <p className="font-mono text-xs text-muted-foreground">{routine.schedule}</p>
                                        </div>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}

                    {can.manage && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Gestão</CardTitle>
                                <CardDescription>A definição do agente é feita pela Rethink; aqui pode suspendê-lo e afectá-lo a pessoas.</CardDescription>
                            </CardHeader>
                            <CardContent className="mt-4 grid gap-4">
                                {agent.status === 'active' ? (
                                    <div className="grid gap-2">
                                        <Input placeholder="Motivo (opcional)" value={reason} onChange={(e) => setReason(e.target.value)} />
                                        <Button variant="outline" onClick={() => setStatus('suspended')}>
                                            <Pause />
                                            Suspender agente
                                        </Button>
                                    </div>
                                ) : agent.status === 'suspended' ? (
                                    <Button onClick={() => setStatus('active')}>
                                        <Play />
                                        Reactivar agente
                                    </Button>
                                ) : null}

                                <div className="grid gap-2">
                                    <p className="text-sm font-medium">Pessoas afectas</p>
                                    <div className="grid max-h-48 gap-1 overflow-y-auto rounded-md border p-2">
                                        {users.map((user) => (
                                            <label key={user.id} className="flex items-center gap-2 text-sm">
                                                <Checkbox
                                                    checked={assignees.includes(user.id)}
                                                    onCheckedChange={(on) => setAssignees(on === true ? [...assignees, user.id] : assignees.filter((id) => id !== user.id))}
                                                />
                                                {user.name}
                                            </label>
                                        ))}
                                    </div>
                                    <Button variant="outline" size="sm" onClick={() => router.put(`/agents/${agent.id}/assignees`, { user_ids: assignees }, { preserveScroll: true })}>
                                        Guardar afectações
                                    </Button>
                                </div>
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>

            <p className="text-xs text-muted-foreground">
                <Link href="/runs" className="hover:underline">
                    Ver todas as execuções
                </Link>
            </p>
        </AppLayout>
    );
}

function Row({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right">{value ?? '—'}</span>
        </div>
    );
}
