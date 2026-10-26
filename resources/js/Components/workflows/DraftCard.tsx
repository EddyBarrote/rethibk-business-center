import { ArrowRight, RotateCcw, TriangleAlert, Waypoints, X } from 'lucide-react';

import { Button } from '@/Components/ui/button';
import { blocks, type BlockType, type WfGraph } from '@/lib/workflows';

/*
 * The flow the assistant drafted on «Criar com IA», shown as a card to
 * confirm, like the agents and skills the Chief of Staff prepares. Opening it
 * fills the editor; nothing is saved until the person presses Guardar there.
 */

export interface WorkflowDraft {
    name: string;
    description: string;
    trigger: 'email' | 'schedule';
    email_category: string | null;
    agent_id: number | null;
    notes: string[];
    graph: WfGraph;
}

export function DraftCard({
    draft,
    facts,
    onApply,
    onRetry,
    onDiscard,
}: {
    draft: WorkflowDraft;
    facts: { label: string; value: string }[];
    onApply: () => void;
    onRetry: () => void;
    onDiscard: () => void;
}) {
    const steps = draft.graph.nodes.filter((node) => node.type !== 'trigger' && node.type !== 'end');
    const label = (id: string) => draft.graph.nodes.find((node) => node.id === id)?.data?.label;

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <div className="flex items-center gap-2 border-b bg-muted/40 px-4 py-2.5">
                <Waypoints className="size-4 shrink-0 text-primary" />
                <p className="min-w-0 flex-1 text-sm font-medium">Rascunho do fluxo para confirmar</p>
            </div>
            <dl className="divide-y">
                {facts
                    .filter((fact) => fact.value !== '')
                    .map((fact) => (
                        <div key={fact.label} className="grid gap-0.5 px-4 py-2 sm:grid-cols-[8rem_1fr] sm:gap-3">
                            <dt className="text-xs text-muted-foreground">{fact.label}</dt>
                            <dd className="min-w-0 text-sm break-words">{fact.value}</dd>
                        </div>
                    ))}
                <div className="grid gap-1 px-4 py-2 sm:grid-cols-[8rem_1fr] sm:gap-3">
                    <dt className="text-xs text-muted-foreground">Passos ({steps.length})</dt>
                    <dd className="min-w-0">
                        <ol className="space-y-1 text-sm">
                            {steps.map((node, index) => {
                                const meta = blocks[node.type as BlockType];
                                const Icon = meta?.icon;
                                const inside = node.parentId ? label(node.parentId) : null;

                                return (
                                    <li key={node.id} className="flex items-start gap-2">
                                        <span className="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums">{index + 1}.</span>
                                        {Icon && <Icon className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />}
                                        <span className="min-w-0">
                                            {node.data?.label ?? meta?.label}
                                            <span className="text-xs text-muted-foreground">
                                                {' · '}
                                                {meta?.label}
                                                {inside ? `, dentro de «${inside}»` : ''}
                                            </span>
                                        </span>
                                    </li>
                                );
                            })}
                        </ol>
                    </dd>
                </div>
            </dl>
            {draft.notes.length > 0 && (
                <div className="flex items-start gap-2 border-t bg-status-warning/8 px-4 py-2 text-xs">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-status-warning" />
                    <ul className="space-y-0.5">
                        {draft.notes.map((note) => (
                            <li key={note}>{note}</li>
                        ))}
                    </ul>
                </div>
            )}
            <div className="flex flex-wrap items-center gap-2 border-t px-4 py-3">
                <Button size="sm" onClick={onApply}>
                    Abrir no editor
                    <ArrowRight />
                </Button>
                <Button size="sm" variant="outline" onClick={onRetry}>
                    <RotateCcw />
                    Mudar a descrição
                </Button>
                <Button size="sm" variant="ghost" onClick={onDiscard}>
                    <X />
                    Descartar
                </Button>
                <p className="text-xs text-muted-foreground">Nada fica guardado até carregar em Guardar no editor.</p>
            </div>
        </div>
    );
}
