import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Markdown } from '@/Components/Markdown';
import { ApprovalCard, ApprovalList } from '@/Components/ApprovalCard';
import { Properties, Property, Section } from '@/Components/Blocks';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { RunRequest, RunTimeline, type Step } from '@/Components/runs/RunTimeline';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, duration, number, usdPrecise } from '@/lib/format';
import type { ApprovalSummary, RunSummary, SharedProps } from '@/types';

export default function RunShow({
    run,
    steps: initialSteps,
    approvals,
    tool_names: toolNames = {},
}: {
    run: RunSummary;
    steps: Step[];
    approvals: ApprovalSummary[];
    tool_names?: Record<string, string>;
}) {
    const { tenant } = usePage<SharedProps>().props;
    const [liveSteps, setLiveSteps] = useState<Step[]>([]);
    const finished = ['completed', 'failed', 'cancelled'].includes(run.status);

    // Steps from the server win; live ones fill in until the next reload.
    const known = new Set(initialSteps.map((step) => step.id));
    const steps = [...initialSteps, ...liveSteps.filter((step) => !known.has(step.id))].sort((a, b) => a.seq - b.seq);

    useLive<{ step?: Step }>(
        tenant ? `tenant.${tenant.id}.run.${run.id}` : null,
        ['AgentRunStepAdded', 'AgentRunStarted', 'AgentRunFinished', 'ApprovalDecided'],
        (event, payload) => {
            if (event === 'AgentRunStepAdded' && payload.step) {
                setLiveSteps((current) => [...current, payload.step as Step]);
            } else {
                router.reload({ only: ['run', 'steps', 'approvals'], onSuccess: () => setLiveSteps([]) });
            }
        },
        { only: ['run', 'steps', 'approvals'], poll: !finished },
    );

    return (
        <AppLayout breadcrumbs={[{ label: 'Definições', href: '/settings' }, { label: 'Execuções', href: '/settings/runs' }, { label: `#${run.id}` }]}>
            <Head title={`Execução #${run.id}`} />

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <AgentAvatar name={run.agent.name} className="size-10 rounded-xl text-xs" />
                    <div className="min-w-0 space-y-0.5">
                        <h1 className="text-xl font-semibold tracking-tight">
                            Execução <span className="font-mono">#{run.id}</span>
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            <Link href={`/agents/${run.agent.id}`} className="hover:text-foreground hover:underline">
                                {run.agent.name}
                            </Link>{' '}
                            · {run.trigger_label} · <span title={dateTime(run.created_at)}>{ago(run.created_at)}</span>
                        </p>
                    </div>
                </div>
                <RunStatusBadge status={run.status} label={run.status_label} />
            </div>

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-8">
                    <Section title="Pedido">
                        <RunRequest input={run.input} />
                    </Section>

                    <Section
                        title="Linha temporal"
                        action={<span className="text-xs text-muted-foreground tabular-nums">{steps.length} passos</span>}
                    >
                        <RunTimeline steps={steps} finished={finished} output={run.output} toolNames={toolNames} />
                    </Section>

                    {run.error && (
                        <div className="flex gap-2 rounded-xl border border-status-danger/30 bg-status-danger/10 px-4 py-3 text-sm text-status-danger">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <span className="min-w-0 break-words whitespace-pre-wrap">{run.error}</span>
                        </div>
                    )}

                    {run.output && (
                        <Section title="Resposta final">
                            <div className="rounded-xl border bg-card px-5 py-3">
                                <Markdown>{run.output}</Markdown>
                            </div>
                        </Section>
                    )}

                    {approvals.length > 0 && (
                        <Section title="Aprovações">
                            <ApprovalList>
                                {approvals.map((approval) => (
                                    <ApprovalCard key={approval.id} approval={approval} />
                                ))}
                            </ApprovalList>
                        </Section>
                    )}
                </div>

                <Properties className="self-start">
                    <Property label="Agente">
                        <Link href={`/agents/${run.agent.id}`} className="hover:underline">
                            {run.agent.name}
                        </Link>
                    </Property>
                    <Property label="Estado">
                        <RunStatusBadge status={run.status} label={run.status_label} />
                    </Property>
                    <Property label="Origem">{run.trigger_label}</Property>
                    <Property label="Pedida por">{run.requested_by}</Property>
                    <Property label="Modelo">
                        {run.model ? <span className="font-mono text-xs">{`${run.provider} · ${run.model}`}</span> : null}
                    </Property>
                    <Property label="Tokens">
                        <span className="tabular-nums" title="Entrada / saída">
                            {number(run.input_tokens)} / {number(run.output_tokens)}
                        </span>
                    </Property>
                    <Property label="Custo">
                        <span className="tabular-nums">{usdPrecise(run.cost_usd)}</span>
                    </Property>
                    <Property label="Duração">
                        {run.duration_ms !== null ? <span className="tabular-nums">{duration(run.duration_ms)}</span> : null}
                    </Property>
                    <Property label="Início">
                        <span className="tabular-nums">{dateTime(run.created_at)}</span>
                    </Property>
                    <Property label="Fim">{run.finished_at ? <span className="tabular-nums">{dateTime(run.finished_at)}</span> : null}</Property>
                </Properties>
            </div>
        </AppLayout>
    );
}

