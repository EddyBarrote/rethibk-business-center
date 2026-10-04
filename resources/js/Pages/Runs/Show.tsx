import { Head, Link, router, usePage } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, Brain, CheckSquare, MessageSquare, Wrench } from 'lucide-react';
import { useState } from 'react';

import { ApprovalCard } from '@/Components/ApprovalCard';
import { PageHeader } from '@/Components/PageHeader';
import { RunStatusBadge } from '@/Components/RunStatusBadge';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { useLive } from '@/hooks/useLive';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime, time, usd } from '@/lib/format';
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

const stepMeta = {
    message: { icon: MessageSquare, label: 'Resposta', tone: 'bg-emerald-100 text-emerald-800' },
    reasoning: { icon: Brain, label: 'Raciocínio', tone: 'bg-slate-100 text-slate-700' },
    tool_call: { icon: Wrench, label: 'Chamada', tone: 'bg-sky-100 text-sky-800' },
    tool_result: { icon: Wrench, label: 'Resultado', tone: 'bg-sky-50 text-sky-800' },
    approval: { icon: CheckSquare, label: 'Aprovação', tone: 'bg-amber-100 text-amber-800' },
    error: { icon: AlertTriangle, label: 'Erro', tone: 'bg-rose-100 text-rose-800' },
};

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
        <AppLayout>
            <Head title={`Execução #${run.id}`} />
            <div>
                <Link href={`/agents/${run.agent.id}`} className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                    <ArrowLeft className="size-4" />
                    {run.agent.name}
                </Link>
            </div>
            <PageHeader title={`Execução #${run.id}`} description={`${run.trigger_label} · ${dateTime(run.created_at)}${run.requested_by ? ` · pedida por ${run.requested_by}` : ''}`} actions={<RunStatusBadge status={run.status} label={run.status_label} />} />

            <div className="grid gap-6 lg:grid-cols-[2fr_1fr]">
                <div className="grid content-start gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Pedido</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-3 text-sm whitespace-pre-wrap">{run.input}</CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Linha temporal</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-4">
                            {steps.length === 0 ? (
                                <p className="text-sm text-muted-foreground">{finished ? 'Sem passos registados.' : 'À espera do primeiro passo…'}</p>
                            ) : (
                                <ol className="relative grid gap-4 border-l pl-6">
                                    {steps.map((step) => (
                                        <StepItem key={step.id} step={step} />
                                    ))}
                                </ol>
                            )}
                        </CardContent>
                    </Card>

                    {run.output && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Resposta final</CardTitle>
                            </CardHeader>
                            <CardContent className="mt-3 text-sm whitespace-pre-wrap">{run.output}</CardContent>
                        </Card>
                    )}
                    {run.error && <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">{run.error}</div>}
                </div>

                <div className="grid content-start gap-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Detalhes</CardTitle>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-2 text-sm">
                            <Detail label="Modelo" value={run.model ? `${run.provider} · ${run.model}` : '—'} />
                            <Detail label="Tokens" value={`${run.input_tokens.toLocaleString('pt-PT')} / ${run.output_tokens.toLocaleString('pt-PT')}`} />
                            <Detail label="Custo" value={usd(run.cost_usd)} />
                            <Detail label="Duração" value={run.duration_ms !== null ? `${(run.duration_ms / 1000).toFixed(1)} s` : '—'} />
                            <Detail label="Terminou" value={dateTime(run.finished_at)} />
                        </CardContent>
                    </Card>

                    {approvals.length > 0 && (
                        <div className="grid gap-3">
                            <p className="text-sm font-medium">Aprovações</p>
                            {approvals.map((approval) => (
                                <ApprovalCard key={approval.id} approval={approval} />
                            ))}
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}

function StepItem({ step }: { step: Step }) {
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
        <li className="relative">
            <span className={cn('absolute top-0 -left-[2.15rem] flex size-6 items-center justify-center rounded-full ring-4 ring-card', meta.tone)}>
                <Icon className="size-3.5" />
            </span>
            <div className="flex flex-wrap items-baseline gap-2 text-sm">
                <span className="font-medium">{meta.label}</span>
                {step.tool_name && <span className="font-mono text-xs text-muted-foreground">{step.tool_name}</span>}
                <span className="text-xs text-muted-foreground">{time(step.created_at)}</span>
                {step.duration_ms !== null && <span className="text-xs text-muted-foreground">{step.duration_ms} ms</span>}
            </div>
            {text && <p className="mt-1 line-clamp-6 text-sm whitespace-pre-wrap text-muted-foreground">{text}</p>}
            <button type="button" onClick={() => setOpen(!open)} className="mt-1 text-xs text-muted-foreground underline-offset-2 hover:underline">
                {open ? 'Esconder dados' : 'Ver dados'}
            </button>
            {open && <pre className="mt-2 max-h-72 overflow-auto rounded-md bg-muted p-3 text-xs">{JSON.stringify(payload, null, 2)}</pre>}
        </li>
    );
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between gap-4">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right tabular-nums">{value}</span>
        </div>
    );
}
