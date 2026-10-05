import { Link, useForm } from '@inertiajs/react';
import { Check, ChevronRight, Code2, Loader2, Lock, ShieldAlert, Sparkles, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { Property } from '@/Components/Blocks';
import { FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { approvalTone, StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { approvalFacts, approvalTitle } from '@/lib/approvals';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ApprovalSummary } from '@/types';

const executionLabel = { not_executed: 'por executar', executed: 'executada', failed: 'falhou' };

/** The arguments as labelled facts; long texts (an email body) below them, as written. */
export function PayloadView({ payload }: { payload: Record<string, unknown> | null }) {
    const facts = approvalFacts(payload);

    if (facts.length === 0) {
        return <p className="text-sm text-muted-foreground">Sem dados.</p>;
    }

    return (
        <dl className="divide-y overflow-hidden rounded-lg border">
            {facts.map((fact) => (
                <div key={fact.label} className={cn('grid gap-1 px-3 py-2', !fact.long && 'sm:grid-cols-[9rem_1fr] sm:gap-3')}>
                    <dt className="text-xs text-muted-foreground">{fact.label}</dt>
                    <dd className={cn('min-w-0 text-sm break-words', fact.long && 'max-h-60 overflow-y-auto whitespace-pre-wrap')}>{fact.value}</dd>
                </div>
            ))}
        </dl>
    );
}

/** Why a person has to decide: the absolute ceiling, or the agent's level below what the action needs. */
function Reason({ approval, short = false }: { approval: ApprovalSummary; short?: boolean }) {
    if (approval.ceiling_reason) {
        return short ? (
            <span
                className="inline-flex min-w-0 items-center gap-1 text-[color-mix(in_oklch,var(--status-danger)_80%,var(--foreground))]"
                title={approval.ceiling_reason}
            >
                <Lock className="size-3 shrink-0" />
                <span className="truncate">Tecto absoluto</span>
            </span>
        ) : (
            <p className="flex items-start gap-2 rounded-lg bg-status-danger/8 px-3 py-2 text-xs text-[color-mix(in_oklch,var(--status-danger)_80%,var(--foreground))]">
                <Lock className="mt-0.5 size-3.5 shrink-0" />
                <span className="min-w-0 break-words">
                    <span className="font-medium">Tecto absoluto: decide sempre uma pessoa.</span> {approval.ceiling_reason}
                </span>
            </p>
        );
    }

    return short ? (
        <span
            className="inline-flex items-center gap-1"
            title={`O agente está em N${approval.agent_level} e esta acção pede N${approval.required_level}`}
        >
            <ShieldAlert className="size-3 shrink-0" />
            pede N{approval.required_level}
        </span>
    ) : (
        <p className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
            <ShieldAlert className="size-3.5" />O agente está em <AutonomyBadge level={approval.agent_level} /> e esta acção pede{' '}
            <AutonomyBadge level={approval.required_level} />
        </p>
    );
}

/** The Chief of Staff's part (realinhamento L11): revalidating first, or the note it left for people. */
function Review({ approval, short = false }: { approval: ApprovalSummary; short?: boolean }) {
    const who = approval.review_agent ?? 'Chief of Staff';

    if (approval.status === 'pending' && approval.review_stage === 'agent') {
        return short ? (
            <span className="inline-flex items-center gap-1 text-status-running">
                <Sparkles className="size-3 shrink-0" />
                Com o {who}
            </span>
        ) : (
            <p className="flex items-start gap-2 rounded-lg bg-status-running/8 px-3 py-2 text-xs">
                <Sparkles className="mt-0.5 size-3.5 shrink-0 text-status-running" />
                <span>
                    <span className="font-medium">O {who} está a revalidar.</span> Aprova o que couber no nível dele e passa o resto a uma pessoa.
                    Pode decidir já, se quiser.
                </span>
            </p>
        );
    }

    if (approval.review_stage === 'human' && approval.review_note) {
        return short ? (
            <span className="inline-flex min-w-0 items-center gap-1" title={approval.review_note}>
                <Sparkles className="size-3 shrink-0" />
                <span className="truncate">Revista pelo {who}</span>
            </span>
        ) : (
            <p className="flex items-start gap-2 rounded-lg bg-muted px-3 py-2 text-xs">
                <Sparkles className="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
                <span>
                    <span className="font-medium">Passada às pessoas pelo {who}:</span> “{approval.review_note}”
                </span>
            </p>
        );
    }

    return null;
}

/**
 * One approval as a row of the queue (section 12.2), the way Paperclip lists them: who asks, the action in words,
 * why a person decides, and the decision. Rejecting asks for the reason; the row opens the details, which show what
 * would be sent, with the tool call folded away under "Detalhes técnicos".
 */
export function ApprovalCard({
    approval,
    taskHref,
    selectable = false,
    selected = false,
    onSelectedChange,
}: {
    approval: ApprovalSummary;
    taskHref?: string | null;
    selectable?: boolean;
    selected?: boolean;
    onSelectedChange?: (selected: boolean) => void;
}) {
    const [details, setDetails] = useState(false);
    const [rejecting, setRejecting] = useState(false);
    const form = useForm({ note: '' });
    const title = approvalTitle(approval.action_type, approval.payload, approval.action_summary);

    const decide = (action: 'approve' | 'reject') =>
        form.post(`/approvals/${approval.id}/${action}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setRejecting(false);
                setDetails(false);
            },
        });

    const pending = approval.status === 'pending';
    const executed = approval.status === 'approved' && approval.execution_status !== 'not_executed';
    const bulkable = approval.can_decide && pending && !approval.ceiling_reason;

    return (
        <div className={cn('group flex min-w-0 flex-col gap-2 px-4 py-3 sm:flex-row sm:items-center sm:gap-3', selected && 'bg-primary/5')}>
            <div className="flex min-w-0 flex-1 items-start gap-3">
                {selectable && (
                    <Checkbox
                        className="mt-1.5"
                        checked={selected}
                        disabled={!bulkable}
                        onCheckedChange={(on) => onSelectedChange?.(on === true)}
                        aria-label={`Seleccionar: ${title}`}
                        title={!bulkable && approval.ceiling_reason ? 'Tecto absoluto: decida esta à parte' : undefined}
                    />
                )}
                <AgentAvatar name={approval.agent.name} className="mt-0.5 size-7" />
                <button type="button" onClick={() => setDetails(true)} className="min-w-0 flex-1 text-left">
                    <span className="line-clamp-2 text-sm leading-snug font-medium break-words underline-offset-4 group-hover:underline group-hover:decoration-border sm:line-clamp-1">
                        {title}
                    </span>
                    <span className="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground">
                        <span>{approval.agent.name}</span>
                        <span aria-hidden="true">·</span>
                        <Reason approval={approval} short />
                        <Review approval={approval} short />
                        <span aria-hidden="true">·</span>
                        <span title={dateTime(approval.created_at)}>{ago(approval.created_at)}</span>
                        {approval.decided_by && (
                            <>
                                <span aria-hidden="true">·</span>
                                <span>
                                    {approval.status_label} por {approval.decided_by}
                                </span>
                            </>
                        )}
                    </span>
                </button>
            </div>

            <div className={cn('flex shrink-0 items-center gap-1.5 sm:pl-0', selectable ? 'pl-[4.25rem]' : 'pl-10')}>
                {approval.can_decide && pending ? (
                    <>
                        <Button size="sm" disabled={form.processing} onClick={() => decide('approve')}>
                            {form.processing && !rejecting ? <Loader2 className="animate-spin" /> : <Check />}
                            Aprovar
                        </Button>
                        <Button size="sm" variant="outline" disabled={form.processing} onClick={() => setRejecting(true)}>
                            <X />
                            Rejeitar
                        </Button>
                    </>
                ) : (
                    <>
                        <StatusBadge tone={approvalTone(approval.status)}>{approval.status_label}</StatusBadge>
                        {approval.status === 'approved' && (
                            <StatusBadge
                                tone={
                                    approval.execution_status === 'failed' ? 'danger' : approval.execution_status === 'executed' ? 'success' : 'idle'
                                }
                                dot={false}
                            >
                                {executionLabel[approval.execution_status]}
                            </StatusBadge>
                        )}
                    </>
                )}
                {/* null (not undefined) means the list has a task column: keep its place so the buttons line up. */}
                {taskHref ? (
                    <Button size="sm" variant="ghost" className="hidden w-16 text-muted-foreground lg:inline-flex" asChild>
                        <Link href={taskHref}>Tarefa</Link>
                    </Button>
                ) : (
                    taskHref === null && <span className="hidden w-16 lg:block" aria-hidden="true" />
                )}
                <Button size="icon-sm" variant="ghost" className="text-muted-foreground" onClick={() => setDetails(true)} aria-label="Ver detalhes">
                    <ChevronRight />
                </Button>
            </div>

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
                        {approval.agent.name} fica a saber porquê e não executa: <span className="text-foreground">{title}</span>
                    </>
                }
                submitLabel="Rejeitar"
                processing={form.processing}
                disabled={form.data.note.trim() === ''}
                onSubmit={() => decide('reject')}
                size="sm"
            >
                <Field
                    id={`reject-note-${approval.id}`}
                    label="Motivo"
                    error={form.errors.note}
                    hint="O agente lê o motivo e ajusta o que faz a seguir."
                >
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
                        <DialogTitle className="pr-6 leading-snug">{title}</DialogTitle>
                        <DialogDescription>
                            Pedido por {approval.agent.name} {ago(approval.created_at)}.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                        <Reason approval={approval} />
                        <Review approval={approval} />
                        <div className="grid gap-2">
                            <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">O que o agente vai fazer</p>
                            <PayloadView payload={approval.payload} />
                        </div>
                        <div className="grid gap-0">
                            <Property label="Estado">
                                <StatusBadge tone={approvalTone(approval.status)}>{approval.status_label}</StatusBadge>
                            </Property>
                            <Property label="Decide">{approval.assigned_to}</Property>
                            {approval.decided_by && (
                                <Property label="Decisão">
                                    {approval.decided_by} · {dateTime(approval.decided_at)}
                                    {approval.decision_note && <span className="block text-muted-foreground">“{approval.decision_note}”</span>}
                                </Property>
                            )}
                        </div>
                        {executed && approval.execution_result?.content && (
                            <div className="grid gap-2">
                                <p className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Resultado</p>
                                <p className="max-h-48 overflow-auto rounded-lg bg-muted p-3 text-sm whitespace-pre-wrap">
                                    {approval.execution_result.content}
                                </p>
                            </div>
                        )}
                        {approval.can_decide && pending && (
                            <Field
                                id={`note-${approval.id}`}
                                label="Nota"
                                error={form.errors.note}
                                hint="Opcional ao aprovar; obrigatória ao rejeitar."
                            >
                                <Textarea
                                    id={`note-${approval.id}`}
                                    rows={2}
                                    value={form.data.note}
                                    onChange={(event) => form.setData('note', event.target.value)}
                                />
                            </Field>
                        )}
                        <Collapsible>
                            <CollapsibleTrigger className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground [&[data-state=open]>svg:last-child]:rotate-90">
                                <Code2 className="size-3.5" />
                                Detalhes técnicos
                                <ChevronRight className="size-3.5 transition-transform" />
                            </CollapsibleTrigger>
                            <CollapsibleContent className="mt-2 grid gap-2">
                                <p className="text-xs text-muted-foreground">
                                    Capacidade <code className="rounded bg-muted px-1 font-mono">{approval.action_type}</code> · execução{' '}
                                    <Link href={`/runs/${approval.run_id}`} className="font-mono hover:underline">
                                        #{approval.run_id}
                                    </Link>{' '}
                                    · aprovação <span className="font-mono">#{approval.id}</span>
                                </p>
                                <pre className="max-h-56 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs">
                                    {JSON.stringify(approval.payload, null, 2)}
                                </pre>
                            </CollapsibleContent>
                        </Collapsible>
                    </div>
                    <DialogFooter className="items-center border-t px-6 py-4 sm:justify-between">
                        <div className="flex gap-1">
                            {taskHref && (
                                <Button variant="ghost" asChild>
                                    <Link href={taskHref}>Abrir a tarefa</Link>
                                </Button>
                            )}
                            <Button variant="ghost" asChild>
                                <Link href={`/runs/${approval.run_id}`}>Ver execução</Link>
                            </Button>
                        </div>
                        {approval.can_decide && pending && (
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
        </div>
    );
}

/** Rows of approvals in one panel, as every list in the console. */
export function ApprovalList({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('divide-y overflow-hidden rounded-xl border bg-card', className)}>{children}</div>;
}
