import { Link, useForm } from '@inertiajs/react';
import { Check, Loader2, Lock, ShieldAlert, X } from 'lucide-react';
import { useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { Property } from '@/Components/Blocks';
import { approvalTone, StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ApprovalSummary } from '@/types';

const executionLabel = { not_executed: 'por executar', executed: 'executada', failed: 'falhou' };

/** What the agent wants to do, as label and value rows instead of raw JSON. */
export function PayloadView({ payload }: { payload: Record<string, unknown> | null }) {
    const entries = Object.entries(payload ?? {});

    if (entries.length === 0) {
        return <p className="text-sm text-muted-foreground">Sem dados.</p>;
    }

    return (
        <dl className="divide-y overflow-hidden rounded-lg border">
            {entries.map(([key, value]) => (
                <div key={key} className="grid gap-1 px-3 py-2 sm:grid-cols-[10rem_1fr] sm:gap-3">
                    <dt className="font-mono text-xs text-muted-foreground">{key}</dt>
                    <dd className="min-w-0 text-sm break-words whitespace-pre-wrap">
                        {value === null || value === '' ? (
                            <span className="text-muted-foreground">—</span>
                        ) : typeof value === 'object' ? (
                            Array.isArray(value) && value.every((item) => typeof item !== 'object') ? (
                                value.join(', ')
                            ) : (
                                <pre className="max-h-48 overflow-auto rounded-md bg-muted p-2 font-mono text-xs">{JSON.stringify(value, null, 2)}</pre>
                            )
                        ) : (
                            String(value)
                        )}
                    </dd>
                </div>
            ))}
        </dl>
    );
}

/** Why a person has to decide: the absolute ceiling, or the agent's level below what the action needs. */
function Reason({ approval }: { approval: ApprovalSummary }) {
    return approval.ceiling_reason ? (
        <p className="flex items-start gap-2 rounded-lg bg-status-danger/8 px-3 py-2 text-xs text-[color-mix(in_oklch,var(--status-danger)_80%,var(--foreground))]">
            <Lock className="mt-0.5 size-3.5 shrink-0" />
            <span className="min-w-0 break-words">
                <span className="font-medium">Tecto absoluto.</span> {approval.ceiling_reason}
            </span>
        </p>
    ) : (
        <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
            <ShieldAlert className="size-3.5" />O agente está em <AutonomyBadge level={approval.agent_level} /> e esta acção pede{' '}
            <AutonomyBadge level={approval.required_level} />
        </p>
    );
}

/**
 * One approval (section 12.2), the way Paperclip shows them: who asks, what,
 * why it needs a person, and the decision. Rejecting asks for the reason in a
 * dialog; the details dialog shows everything the agent would send.
 */
export function ApprovalCard({ approval, compact = false }: { approval: ApprovalSummary; compact?: boolean }) {
    const [details, setDetails] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const form = useForm({ note: '' });

    const decide = (action: 'approve' | 'reject') =>
        form.post(`/approvals/${approval.id}/${action}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setRejecting(false);
                setDetails(false);
            },
        });

    const executed = approval.status === 'approved' && approval.execution_status !== 'not_executed';

    return (
        <article className="flex min-w-0 flex-col gap-3 rounded-xl border bg-card p-4">
            <header className="flex items-start gap-3">
                <AgentAvatar name={approval.agent.name} className="size-8" />
                <div className="min-w-0 flex-1">
                    <p className="text-sm leading-snug font-medium break-words">{approval.action_summary}</p>
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs text-muted-foreground">
                        <Link href={`/agents/${approval.agent.id}`} className="hover:text-foreground hover:underline">
                            {approval.agent.name}
                        </Link>
                        <span aria-hidden="true">·</span>
                        <span className="font-mono">{approval.action_type}</span>
                        <span aria-hidden="true">·</span>
                        <Link href={`/runs/${approval.run_id}`} className="font-mono hover:text-foreground hover:underline">
                            #{approval.run_id}
                        </Link>
                        <span aria-hidden="true">·</span>
                        <span title={dateTime(approval.created_at)}>{ago(approval.created_at)}</span>
                    </p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                    <StatusBadge tone={approvalTone(approval.status)}>{approval.status_label}</StatusBadge>
                    {approval.status === 'approved' && (
                        <StatusBadge tone={approval.execution_status === 'failed' ? 'danger' : approval.execution_status === 'executed' ? 'success' : 'idle'} dot={false}>
                            {executionLabel[approval.execution_status]}
                        </StatusBadge>
                    )}
                </div>
            </header>

            <Reason approval={approval} />

            {approval.decided_by && (
                <p className="text-xs text-muted-foreground">
                    {approval.status_label} por <span className="text-foreground">{approval.decided_by}</span> {ago(approval.decided_at)}
                    {approval.decision_note && <> · “{approval.decision_note}”</>}
                </p>
            )}

            {executed && !compact && approval.execution_result?.content && (
                <p className="line-clamp-3 rounded-lg bg-muted px-3 py-2 text-xs whitespace-pre-wrap text-muted-foreground">{approval.execution_result.content}</p>
            )}

            <footer className={cn('flex flex-wrap items-center gap-2', approval.can_decide ? 'justify-between' : 'justify-end')}>
                {approval.can_decide && (
                    <div className="flex items-center gap-2">
                        <Button size="sm" disabled={form.processing} onClick={() => decide('approve')}>
                            {form.processing && !rejecting ? <Loader2 className="animate-spin" /> : <Check />}
                            Aprovar
                        </Button>
                        <Button size="sm" variant="outline" disabled={form.processing} onClick={() => setRejecting(true)}>
                            <X />
                            Rejeitar
                        </Button>
                    </div>
                )}
                <Button size="sm" variant="ghost" className="text-muted-foreground" onClick={() => setDetails(true)}>
                    Ver detalhes
                </Button>
            </footer>

            <FormDialog
                open={rejecting}
                onOpenChange={(open) => {
                    setRejecting(open);
                    if (!open) {
                        form.clearErrors();
                    }
                }}
                title="Rejeitar esta acção?"
                description={
                    <>
                        {approval.agent.name} fica a saber porquê e não executa: <span className="text-foreground">{approval.action_summary}</span>
                    </>
                }
                submitLabel="Rejeitar"
                processing={form.processing}
                disabled={form.data.note.trim() === ''}
                onSubmit={() => decide('reject')}
                size="sm"
            >
                <Field id={`reject-note-${approval.id}`} label="Motivo" error={form.errors.note} hint="O agente lê o motivo e ajusta o que faz a seguir.">
                    <Textarea
                        id={`reject-note-${approval.id}`}
                        rows={3}
                        autoFocus
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                        placeholder="Ex.: o cliente já pagou; não enviar o lembrete."
                    />
                </Field>
            </FormDialog>

            <Dialog open={details} onOpenChange={setDetails}>
                <DialogContent className="flex max-h-[calc(100dvh-2rem)] flex-col gap-0 p-0 sm:max-w-2xl">
                    <DialogHeader className="border-b px-6 pt-6 pb-4">
                        <DialogTitle className="pr-6 leading-snug">{approval.action_summary}</DialogTitle>
                        <DialogDescription>
                            Pedido por {approval.agent.name} {ago(approval.created_at)}, na execução #{approval.run_id}.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                        <div className="grid gap-0">
                            <Property label="Estado">
                                <StatusBadge tone={approvalTone(approval.status)}>{approval.status_label}</StatusBadge>
                            </Property>
                            <Property label="Acção">
                                <span className="font-mono text-xs">{approval.action_type}</span>
                            </Property>
                            <Property label="Responsável">{approval.assigned_to}</Property>
                            {approval.decided_by && (
                                <Property label="Decisão">
                                    {approval.decided_by} · {dateTime(approval.decided_at)}
                                    {approval.decision_note && <span className="block text-muted-foreground">“{approval.decision_note}”</span>}
                                </Property>
                            )}
                        </div>
                        <Reason approval={approval} />
                        <div className="grid gap-2">
                            <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">O que o agente vai fazer</p>
                            <PayloadView payload={approval.payload} />
                        </div>
                        {executed && approval.execution_result?.content && (
                            <div className="grid gap-2">
                                <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Resultado</p>
                                <pre className="max-h-48 overflow-auto rounded-lg bg-muted p-3 text-xs whitespace-pre-wrap">{approval.execution_result.content}</pre>
                            </div>
                        )}
                        {approval.can_decide && (
                            <Field id={`note-${approval.id}`} label="Nota" error={form.errors.note} hint="Opcional ao aprovar; obrigatória ao rejeitar.">
                                <Textarea id={`note-${approval.id}`} rows={2} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} />
                            </Field>
                        )}
                    </div>
                    <DialogFooter className="items-center border-t px-6 py-4 sm:justify-between">
                        <Button variant="ghost" asChild>
                            <Link href={`/runs/${approval.run_id}`}>Ver execução</Link>
                        </Button>
                        {approval.can_decide && (
                            <div className="flex flex-col-reverse gap-2 sm:flex-row">
                                <Button variant="outline" disabled={form.processing || form.data.note.trim() === ''} onClick={() => decide('reject')}>
                                    <X />
                                    Rejeitar
                                </Button>
                                <Button disabled={form.processing} onClick={() => decide('approve')}>
                                    {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                                    Aprovar
                                </Button>
                            </div>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </article>
    );
}
