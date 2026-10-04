import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, Brain, CheckSquare, CornerDownRight, type LucideIcon, MessageSquare, Wrench } from 'lucide-react';
import { useState } from 'react';

import { ApprovalCard } from '@/Components/ApprovalCard';
import { Monogram, Properties, Property, Section } from '@/Components/Blocks';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { StatusDot, type Tone } from '@/Components/Status';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, time, usd } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ApprovalSummary, RunSummary, SharedProps } from '@/types';

interface Step {
    id: number;
    seq: number;
    type: 'message' | 'reasoning' | 'tool_call' | 'tool_result' | 'approval' | 'error';
    tool_name: string | null;
    payload: Record<string, unknown> | null;
    duration_ms: number | null;
    created_at: string;
}

const stepMeta: Record<Step['type'], { icon: LucideIcon; label: string; tone: Tone }> = {
    message: { icon: MessageSquare, label: 'Resposta', tone: 'success' },
    reasoning: { icon: Brain, label: 'Raciocínio', tone: 'idle' },
    tool_call: { icon: Wrench, label: 'Chamada', tone: 'running' },
    tool_result: { icon: CornerDownRight, label: 'Resultado', tone: 'idle' },
    approval: { icon: CheckSquare, label: 'Aprovação', tone: 'warning' },
    error: { icon: AlertTriangle, label: 'Erro', tone: 'danger' },
};

const iconTone: Record<Tone, string> = {
    running: 'bg-status-running/12 text-status-running',
    success: 'bg-status-success/12 text-status-success',
    warning: 'bg-status-warning/15 text-status-warning',
    danger: 'bg-status-danger/12 text-status-danger',
    idle: 'bg-muted text-muted-foreground',
};

const duration = (ms: number | null) => (ms === null ? null : ms < 1000 ? `${ms} ms` : `${(ms / 1000).toFixed(1)} s`);
const number = (value: number) => value.toLocaleString('pt-PT');

export default function RunShow({ run, steps: initialSteps, approvals }: { run: RunSummary; steps: Step[]; approvals: ApprovalSummary[] }) {
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
        <AppLayout breadcrumbs={[{ label: 'Execuções', href: '/runs' }, { label: `#${run.id}` }]}>
            <Head title={`Execução #${run.id}`} />

            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex min-w-0 items-center gap-3">
                    <Monogram name={run.agent.name} agent className="size-10 rounded-xl text-xs" />
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
                        <div className="rounded-xl border bg-card p-4 text-sm leading-relaxed whitespace-pre-wrap">{run.input}</div>
                    </Section>

                    <Section
                        title="Linha temporal"
                        action={<span className="text-xs text-muted-foreground tabular-nums">{steps.length} passos</span>}
                    >
                        {steps.length === 0 ? (
                            <div className="flex items-center gap-2 rounded-xl border border-dashed px-4 py-6 text-sm text-muted-foreground">
                                {!finished && <StatusDot tone="running" />}
                                {finished ? 'Sem passos registados.' : 'À espera do primeiro passo…'}
                            </div>
                        ) : (
                            <ol className="flex flex-col">
                                {steps.map((step, index) => (
                                    <StepItem key={step.id} step={step} last={index === steps.length - 1 && finished} />
                                ))}
                                {!finished && (
                                    <li className="flex items-center gap-3 pl-1.5 text-xs text-muted-foreground">
                                        <StatusDot tone="running" />A trabalhar…
                                    </li>
                                )}
                            </ol>
                        )}
                    </Section>

                    {run.error && (
                        <div className="flex gap-2 rounded-xl border border-status-danger/30 bg-status-danger/10 px-4 py-3 text-sm text-status-danger">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <span className="min-w-0 break-words whitespace-pre-wrap">{run.error}</span>
                        </div>
                    )}

                    {run.output && (
                        <Section title="Resposta final">
                            <div className="rounded-xl border bg-card p-4 text-sm leading-relaxed whitespace-pre-wrap">{run.output}</div>
                        </Section>
                    )}

                    {approvals.length > 0 && (
                        <Section title="Aprovações">
                            <div className="grid gap-3">
                                {approvals.map((approval) => (
                                    <ApprovalCard key={approval.id} approval={approval} />
                                ))}
                            </div>
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
                        <span className="font-mono text-xs tabular-nums" title="Entrada / saída">
                            {number(run.input_tokens)} / {number(run.output_tokens)}
                        </span>
                    </Property>
                    <Property label="Custo">
                        <span className="font-mono tabular-nums">{usd(run.cost_usd)}</span>
                    </Property>
                    <Property label="Duração">
                        {run.duration_ms !== null ? <span className="font-mono tabular-nums">{duration(run.duration_ms)}</span> : null}
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

function StepItem({ step, last }: { step: Step; last: boolean }) {
    const [open, setOpen] = useState(false);
    const meta = stepMeta[step.type] ?? stepMeta.message;
    const Icon = meta.icon;
    const payload = step.payload ?? {};
    const pick = (...keys: string[]) => keys.map((key) => payload[key]).find((value): value is string => typeof value === 'string') ?? null;
    const text =
        step.type === 'approval'
            ? [pick('summary'), pick('reason'), pick('note')].filter(Boolean).join(' · ') || null
            : pick('content', 'text', 'message');

    return (
        <li className="relative flex gap-3 pb-5">
            {!last && <span className="absolute top-7 bottom-0 left-3 w-px -translate-x-1/2 bg-border" aria-hidden="true" />}
            <span
                className={cn('relative flex size-6 shrink-0 items-center justify-center rounded-full ring-4 ring-background', iconTone[meta.tone])}
            >
                <Icon className="size-3.5" />
            </span>
            <div className="min-w-0 flex-1 pt-0.5">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-sm">
                    <span className="font-medium">{meta.label}</span>
                    {step.tool_name && <code className="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{step.tool_name}</code>}
                    <span className="ml-auto flex items-baseline gap-2 font-mono text-[11px] text-muted-foreground tabular-nums">
                        {step.duration_ms !== null && <span>{duration(step.duration_ms)}</span>}
                        <span title={dateTime(step.created_at)}>{time(step.created_at)}</span>
                    </span>
                </div>
                {text && (
                    <p
                        className={cn(
                            'mt-1 line-clamp-6 text-sm whitespace-pre-wrap',
                            step.type === 'error' ? 'text-status-danger' : step.type === 'message' ? 'text-foreground' : 'text-muted-foreground',
                        )}
                    >
                        {text}
                    </p>
                )}
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    className="mt-1 text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                >
                    {open ? 'Esconder dados' : 'Ver dados'}
                </button>
                {open && (
                    <pre className="mt-2 max-h-72 overflow-auto rounded-lg border bg-muted/50 p-3 font-mono text-xs">
                        {JSON.stringify(payload, null, 2)}
                    </pre>
                )}
            </div>
        </li>
    );
}
