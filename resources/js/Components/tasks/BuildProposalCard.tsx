import { useForm } from '@inertiajs/react';
import { BookOpen, Check, Loader2, TriangleAlert, UserPlus, X } from 'lucide-react';
import { useState } from 'react';

import { InputError } from '@/Components/InputError';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

/*
 * A new agent or skill the Chief of Staff prepared in the conversation
 * (docs/DECISOES.md, «Chief of Staff cria agentes e skills»). Nothing exists
 * until a person who may create it confirms here; then it is in Agentes and
 * the Organigrama (or in Skills).
 */

export interface BuildProposal {
    kind: 'agent' | 'skill';
    facts: { label: string; value: string }[];
    /** What no longer resolves (an area deleted since): confirming would fail. */
    missing: string[];
}

const LONG = ['Instruções', 'Descrição'];

export function BuildProposalCard({
    approvalId,
    proposal,
    canDecide,
    reload,
}: {
    approvalId: number;
    proposal: BuildProposal;
    canDecide: boolean;
    reload: string[];
}) {
    const [rejecting, setRejecting] = useState(false);
    const [expanded, setExpanded] = useState(false);
    const form = useForm({ note: '' });
    const agent = proposal.kind === 'agent';
    const Icon = agent ? UserPlus : BookOpen;
    const decide = (action: 'approve' | 'reject') =>
        form.post(`/approvals/${approvalId}/${action}`, { preserveScroll: true, only: [...reload, 'flash'] });
    const short = proposal.facts.filter((fact) => !LONG.includes(fact.label));
    const long = proposal.facts.filter((fact) => LONG.includes(fact.label));

    return (
        <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <div className="flex items-center gap-2 border-b bg-muted/40 px-4 py-2.5">
                <Icon className="size-4 shrink-0 text-primary" />
                <p className="min-w-0 flex-1 text-sm font-medium">{agent ? 'Agente novo para confirmar' : 'Skill nova para confirmar'}</p>
            </div>
            <dl className="divide-y">
                {short.map((fact) => (
                    <div key={fact.label} className="grid gap-0.5 px-4 py-2 sm:grid-cols-[8rem_1fr] sm:gap-3">
                        <dt className="text-xs text-muted-foreground">{fact.label}</dt>
                        <dd className="min-w-0 text-sm break-words">{fact.value}</dd>
                    </div>
                ))}
                {long.map((fact) => (
                    <div key={fact.label} className="grid gap-1 px-4 py-2">
                        <dt className="text-xs text-muted-foreground">{fact.label}</dt>
                        <dd className={cn('min-w-0 text-sm break-words whitespace-pre-wrap', !expanded && 'line-clamp-4')}>{fact.value}</dd>
                        {fact.value.length > 280 && (
                            <button
                                type="button"
                                onClick={() => setExpanded(!expanded)}
                                className="justify-self-start text-xs text-primary hover:underline"
                            >
                                {expanded ? 'Mostrar menos' : 'Ver tudo'}
                            </button>
                        )}
                    </div>
                ))}
            </dl>
            {proposal.missing.length > 0 && (
                <p className="flex items-start gap-2 border-t bg-status-warning/8 px-4 py-2 text-xs">
                    <TriangleAlert className="mt-0.5 size-3.5 shrink-0 text-status-warning" />
                    <span>Já não dá para criar assim. Falta {proposal.missing.join('; ')}. Rejeite e diga o que mudar.</span>
                </p>
            )}
            <div className="border-t px-4 py-3">
                {canDecide ? (
                    rejecting ? (
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Textarea
                                rows={1}
                                autoFocus
                                placeholder="O que mudar, ou porque não"
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                                className="min-h-9 bg-background"
                            />
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="destructive"
                                    disabled={form.processing || form.data.note.trim() === ''}
                                    onClick={() => decide('reject')}
                                >
                                    Não criar
                                </Button>
                                <Button size="sm" variant="ghost" onClick={() => setRejecting(false)}>
                                    Cancelar
                                </Button>
                            </div>
                        </div>
                    ) : (
                        <div className="flex flex-wrap items-center gap-2">
                            <Button size="sm" disabled={form.processing || proposal.missing.length > 0} onClick={() => decide('approve')}>
                                {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                                {agent ? 'Criar agente' : 'Criar skill'}
                            </Button>
                            <Button size="sm" variant="outline" disabled={form.processing} onClick={() => setRejecting(true)}>
                                <X />
                                Não criar…
                            </Button>
                            <p className="text-xs text-muted-foreground">Só fica criado depois de confirmar.</p>
                        </div>
                    )
                ) : (
                    <p className="text-xs text-muted-foreground">
                        {agent ? 'Confirma quem pode criar agentes.' : 'Confirma quem gere as skills.'} Até lá não fica criado.
                    </p>
                )}
                <InputError message={form.errors.note} />
            </div>
        </div>
    );
}
