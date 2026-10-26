import { Head, Link, router } from '@inertiajs/react';
import { Check, CircleAlert, Loader2, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { DraftCard, type WorkflowDraft } from '@/Components/workflows/DraftCard';
import AppLayout from '@/Layouts/AppLayout';
import { postStream } from '@/lib/http';
import { cn } from '@/lib/utils';
import { blocks, type BlockType } from '@/lib/workflows';
import type { Option } from '@/types';

/*
 * «Criar com IA»: the person describes a flow; while the assistant drafts it,
 * the page shows each stage and each step as it arrives, so it never looks
 * stuck. The draft opens in the editor, where nothing is saved until Guardar.
 */

interface Props {
    workflow: { id: number; name: string } | null;
    category: Option | null;
    trigger: 'email' | 'schedule' | null;
    trigger_label: string | null;
    agent: { id: number; name: string } | null;
    agents: { id: number; name: string }[];
    categories: Option[];
    can_draft: boolean;
}

type Line =
    | { type: 'stage'; text: string }
    | { type: 'step'; kind: string; label: string }
    | { type: 'draft'; draft: WorkflowDraft; token: string }
    | { type: 'error'; message: string };

type Activity = { kind: 'stage' | 'step'; text: string; block?: string; at: number };

const EXAMPLES = [
    'Quando chega um lead novo, ver no ERP se já é cliente; se não for, registar a oportunidade e responder a propor uma reunião; se for, passar ao Gestor de Clientes.',
    'Quando um cliente pede cotação, ver os materiais, pedir preços a três fornecedores, esperar cinco dias e preparar a proposta; acima de 500 000 MZN a directora aprova.',
    'Todas as segundas de manhã, resumir os pedidos de cotação da semana anterior e enviar o resumo à direcção comercial.',
];

export default function WorkflowDescribe({ workflow, category, trigger, trigger_label, agent, agents, categories, can_draft }: Props) {
    const [text, setText] = useState('');
    const [running, setRunning] = useState(false);
    const [activity, setActivity] = useState<Activity[]>([]);
    const [result, setResult] = useState<{ draft: WorkflowDraft; token: string } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [started, setStarted] = useState(0);
    const [now, setNow] = useState(Date.now());
    const bottom = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, [running]);

    useEffect(() => bottom.current?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }), [activity.length]);

    const start = () => {
        setRunning(true);
        setError(null);
        setResult(null);
        setStarted(Date.now());
        setNow(Date.now());
        setActivity([{ kind: 'stage', text: 'A ler a sua descrição…', at: Date.now() }]);

        postStream<Line>(
            '/settings/workflows/draft/stream',
            {
                description: text,
                agent_id: agent?.id ?? null,
                trigger: trigger ?? (category ? 'email' : null),
                email_category: category?.value ?? null,
            },
            (line) => {
                if (line.type === 'stage') {
                    setActivity((rows) => [...rows, { kind: 'stage', text: line.text, at: Date.now() }]);
                } else if (line.type === 'step') {
                    setActivity((rows) => [...rows, { kind: 'step', text: line.label, block: line.kind, at: Date.now() }]);
                } else if (line.type === 'draft') {
                    setResult({ draft: line.draft, token: line.token });
                } else {
                    setError(line.message);
                }
            },
        )
            .catch((failure: Error) => setError(failure.message))
            .finally(() => setRunning(false));
    };

    const open = (token: string) =>
        router.visit(workflow ? `/settings/workflows/${workflow.id}/edit?draft=${token}` : `/settings/workflows/new?draft=${token}`);

    const facts = (draft: WorkflowDraft) => {
        const chosen = agents.find((row) => row.id === draft.agent_id);
        const kind = categories.find((row) => row.value === draft.email_category);

        return [
            { label: 'Nome', value: draft.name },
            { label: 'Para que serve', value: draft.description },
            { label: 'Como começa', value: draft.trigger === 'email' ? `Email: ${kind?.label ?? 'tipo por escolher'}` : 'Por agenda' },
            { label: 'Agente', value: chosen ? chosen.name + (agent && agent.id !== chosen.id ? ` (em vez de ${agent.name})` : '') : 'Por escolher' },
        ];
    };

    const steps = activity.filter((row) => row.kind === 'step').length;
    const seconds = Math.max(0, Math.round((now - started) / 1000));
    const back = workflow ? `/settings/workflows/${workflow.id}/edit` : `/settings/workflows/new${category ? `?category=${category.value}` : ''}`;

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'Definições', href: '/settings' },
                { label: 'Fluxos de trabalho', href: '/settings/workflows' },
                ...(workflow ? [{ label: workflow.name, href: `/settings/workflows/${workflow.id}/edit` }] : []),
                { label: 'Criar com IA' },
            ]}
        >
            <Head title="Criar fluxo com IA" />

            <div className="mx-auto w-full max-w-3xl space-y-4">
                <div className="space-y-1">
                    <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                        <Sparkles className="size-5 text-primary" />
                        {workflow ? `Redesenhar «${workflow.name}» com IA` : 'Criar fluxo com IA'}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Descreva o que deve acontecer, em palavras. O assistente prepara o nome, como começa, o agente que o trata e os passos, só com
                        o que os agentes sabem fazer. Revê tudo no editor antes de guardar.
                    </p>
                    {(category || trigger_label || agent) && (
                        <p className="flex flex-wrap gap-x-4 gap-y-1 pt-1 text-xs text-muted-foreground">
                            {(category || trigger_label) && (
                                <span>
                                    Começa com: <span className="text-foreground">{category ? `Email · ${category.label}` : trigger_label}</span>
                                </span>
                            )}
                            {agent && (
                                <span>
                                    Agente sugerido: <span className="text-foreground">{agent.name}</span>
                                </span>
                            )}
                        </p>
                    )}
                </div>

                {!can_draft ? (
                    <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">
                        O assistente não está disponível: falta a chave da API do provedor de IA. Pode{' '}
                        <Link href={back} className="text-primary hover:underline">
                            desenhar o fluxo à mão
                        </Link>
                        .
                    </p>
                ) : (
                    <>
                        <div className="space-y-3 rounded-xl border bg-card p-4">
                            <Textarea
                                rows={6}
                                autoFocus
                                aria-label="O fluxo de que precisa"
                                value={text}
                                disabled={running}
                                onChange={(event) => setText(event.target.value)}
                                placeholder="Quando chega… o agente deve… se… então… quem aprova…"
                            />
                            {text.trim() === '' && (
                                <div className="space-y-1.5">
                                    <p className="text-xs text-muted-foreground">Exemplos</p>
                                    <div className="flex flex-col gap-1.5">
                                        {EXAMPLES.map((example) => (
                                            <button
                                                key={example}
                                                type="button"
                                                onClick={() => setText(example)}
                                                className="rounded-lg border px-3 py-2 text-left text-xs text-muted-foreground hover:border-primary/40 hover:text-foreground"
                                            >
                                                {example}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                            )}
                            <div className="flex flex-wrap items-center gap-2">
                                <Button disabled={running || text.trim().length < 15} onClick={start}>
                                    {running ? <Loader2 className="animate-spin" /> : <Sparkles />}
                                    {running ? 'A preparar…' : result ? 'Preparar de novo' : 'Preparar rascunho'}
                                </Button>
                                <Button variant="ghost" asChild>
                                    <Link href={back}>Desenhar à mão</Link>
                                </Button>
                            </div>
                        </div>

                        {activity.length > 0 && (
                            <div className="overflow-hidden rounded-xl border bg-card" aria-live="polite">
                                <div className="flex items-center gap-2 border-b bg-muted/40 px-4 py-2.5 text-sm">
                                    {running ? (
                                        <Loader2 className="size-4 animate-spin text-primary" />
                                    ) : error ? (
                                        <CircleAlert className="size-4 text-status-danger" />
                                    ) : (
                                        <Check className="size-4 text-status-success" />
                                    )}
                                    <p className="min-w-0 flex-1 font-medium">
                                        {running ? 'O assistente está a trabalhar' : error ? 'Não ficou pronto' : 'Rascunho pronto'}
                                    </p>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {steps > 0 && `${steps} ${steps === 1 ? 'passo' : 'passos'} · `}
                                        {running ? `${seconds} s` : ''}
                                    </span>
                                </div>
                                <ol className="max-h-80 space-y-1.5 overflow-y-auto px-4 py-3 text-sm">
                                    {activity.map((row, index) => {
                                        const last = index === activity.length - 1;
                                        const Icon = row.kind === 'step' ? (blocks[row.block as BlockType]?.icon ?? Sparkles) : null;

                                        return (
                                            <li key={index} className={cn('flex items-start gap-2', row.kind === 'step' && 'pl-6')}>
                                                {row.kind === 'stage' ? (
                                                    running && last ? (
                                                        <Loader2 className="mt-0.5 size-3.5 shrink-0 animate-spin text-primary" />
                                                    ) : (
                                                        <Check className="mt-0.5 size-3.5 shrink-0 text-status-success" />
                                                    )
                                                ) : (
                                                    Icon && <Icon className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                                                )}
                                                <span className={cn('min-w-0', row.kind === 'stage' ? 'text-foreground' : 'text-muted-foreground')}>
                                                    {row.kind === 'step' && <span className="text-xs">Passo proposto: </span>}
                                                    {row.text}
                                                    {row.kind === 'step' && row.block && blocks[row.block as BlockType] && (
                                                        <span className="text-xs"> · {blocks[row.block as BlockType].label}</span>
                                                    )}
                                                </span>
                                            </li>
                                        );
                                    })}
                                    {error && (
                                        <li className="flex items-start gap-2 text-status-danger">
                                            <CircleAlert className="mt-0.5 size-3.5 shrink-0" />
                                            <span>{error}</span>
                                        </li>
                                    )}
                                </ol>
                                <div ref={bottom} />
                            </div>
                        )}

                        {result && (
                            <DraftCard
                                draft={result.draft}
                                facts={facts(result.draft)}
                                onApply={() => open(result.token)}
                                onRetry={() => {
                                    setResult(null);
                                    setActivity([]);
                                }}
                                onDiscard={() => {
                                    setResult(null);
                                    setActivity([]);
                                    setText('');
                                }}
                            />
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
