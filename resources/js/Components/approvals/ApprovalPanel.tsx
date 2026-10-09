import { Link, useForm } from '@inertiajs/react';
import {
    Activity,
    ArrowUpRight,
    Check,
    ChevronDown,
    ChevronRight,
    ChevronUp,
    CircleDot,
    Code2,
    Loader2,
    type LucideIcon,
    MessagesSquare,
    Play,
    Sparkles,
    X,
} from 'lucide-react';
import { useEffect } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { executionLabel, PayloadView, Reason, Review } from '@/Components/ApprovalCard';
import { approvalTone, StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/Components/ui/collapsible';
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@/Components/ui/sheet';
import { Textarea } from '@/Components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import { approvalRecipient, approvalTitle } from '@/lib/approvals';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ApprovalSummary } from '@/types';

/** One approval as the approvals page lists it, and, when open, where it came from. */
export interface ApprovalRow extends ApprovalSummary {
    area: string;
}

export interface OpenApproval extends ApprovalRow {
    run: { id: number; title: string | null; status: string; status_label: string; requested_by: string | null } | null;
    task: { id: number; ref: string; title: string } | null;
}

/**
 * The details of one approval on the right of the approvals page, in the manner
 * of the conversation side panel: what the agent wants to do and why a person
 * decides, the conversation and run it came from, its path so far, and the
 * decision at the bottom. Up and down walk the list without closing it.
 */
export function ApprovalPanel({
    approval,
    loading,
    areaLabel,
    position,
    onPrevious,
    onNext,
    onClose,
    onDecided,
}: {
    approval: ApprovalRow | OpenApproval | null;
    loading: boolean;
    areaLabel?: string;
    position: { index: number; total: number } | null;
    onPrevious: (() => void) | null;
    onNext: (() => void) | null;
    onClose: () => void;
    onDecided: () => void;
}) {
    return (
        <Sheet open={approval !== null} onOpenChange={(open) => !open && onClose()}>
            <SheetContent
                side="right"
                showCloseButton={false}
                // Focus stays put, so no tooltip pops up on the first button.
                onOpenAutoFocus={(event) => event.preventDefault()}
                className="w-full gap-0 p-0 sm:max-w-xl"
            >
                {approval && (
                    <Body
                        key={approval.id}
                        approval={approval}
                        loading={loading}
                        areaLabel={areaLabel}
                        position={position}
                        onPrevious={onPrevious}
                        onNext={onNext}
                        onClose={onClose}
                        onDecided={onDecided}
                    />
                )}
            </SheetContent>
        </Sheet>
    );
}

function Body({
    approval,
    loading,
    areaLabel,
    position,
    onPrevious,
    onNext,
    onClose,
    onDecided,
}: {
    approval: ApprovalRow | OpenApproval;
    loading: boolean;
    areaLabel?: string;
    position: { index: number; total: number } | null;
    onPrevious: (() => void) | null;
    onNext: (() => void) | null;
    onClose: () => void;
    onDecided: () => void;
}) {
    const form = useForm({ note: '' });
    const title = approvalTitle(approval.action_type, approval.payload, approval.action_summary);
    const recipient = approvalRecipient(approval.action_type, approval.payload);
    const pending = approval.status === 'pending';
    const decidable = approval.can_decide && pending;
    const full = 'task' in approval ? approval : null;

    const decide = (action: 'approve' | 'reject') =>
        form.post(`/approvals/${approval.id}/${action}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                form.reset();
                onDecided();
            },
        });

    // J and K walk the list, as in a mail client; never while writing a note.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const target = event.target as HTMLElement | null;
            if (event.metaKey || event.ctrlKey || event.altKey || target?.closest('input, textarea, [contenteditable]')) {
                return;
            }
            if (event.key === 'j' && onNext) {
                onNext();
            } else if (event.key === 'k' && onPrevious) {
                onPrevious();
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onNext, onPrevious]);

    const conversation = full?.task ? `/tasks/${full.task.id}?execucao=${approval.run_id}` : approval.task_id ? `/tasks/${approval.task_id}?execucao=${approval.run_id}` : null;

    return (
        <div className="flex h-full min-h-0 flex-col">
            <div className="flex h-12 shrink-0 items-center gap-1 border-b px-3">
                <StatusBadge tone={approvalTone(approval.status)}>{approval.status === 'pending' ? 'À espera de decisão' : approval.status_label}</StatusBadge>
                {areaLabel && <span className="ml-1 truncate text-xs text-muted-foreground">{areaLabel}</span>}
                <div className="ml-auto flex items-center gap-0.5">
                    {position && (
                        <span className="mr-1 text-xs text-muted-foreground tabular-nums">
                            {position.index + 1} de {position.total}
                        </span>
                    )}
                    <NavButton icon={ChevronUp} label="Anterior" shortcut="K" onClick={onPrevious} />
                    <NavButton icon={ChevronDown} label="Seguinte" shortcut="J" onClick={onNext} />
                    <Button variant="ghost" size="icon-sm" className="text-muted-foreground" onClick={onClose} aria-label="Fechar">
                        <X />
                    </Button>
                </div>
            </div>

            <div className="flex min-h-0 flex-1 flex-col gap-5 overflow-y-auto px-5 py-5">
                <div className="flex flex-col gap-2">
                    <SheetTitle className="text-lg leading-snug font-semibold break-words">{title}</SheetTitle>
                    <SheetDescription asChild>
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                            <AgentAvatar name={approval.agent.name} className="size-5" />
                            <Link href={`/agents/${approval.agent.id}`} className="font-medium text-foreground hover:underline">
                                {approval.agent.name}
                            </Link>
                            <span>pediu {ago(approval.created_at)}</span>
                            {recipient && (
                                <>
                                    <span aria-hidden="true">·</span>
                                    <span className="max-w-full truncate">{recipient}</span>
                                </>
                            )}
                        </div>
                    </SheetDescription>
                </div>

                <Reason approval={approval} />
                <Review approval={approval} />

                <section className="grid gap-2">
                    <Heading>O que o agente vai fazer</Heading>
                    <PayloadView payload={approval.payload} />
                </section>

                <section className="grid gap-2">
                    <Heading>De onde vem</Heading>
                    <div className="divide-y overflow-hidden rounded-lg border">
                        {conversation ? (
                            <ContextLink
                                href={conversation}
                                icon={MessagesSquare}
                                title={full?.task ? `${full.task.ref} · ${full.task.title}` : 'Abrir a conversa'}
                                meta="A conversa, com esta execução aberta ao lado"
                            />
                        ) : null}
                        <ContextLink
                            href={`/settings/runs/${approval.run_id}`}
                            icon={Activity}
                            title={full?.run?.title ?? `Execução #${approval.run_id}`}
                            meta={
                                full?.run
                                    ? [full.run.status_label, full.run.requested_by && `pedida por ${full.run.requested_by}`].filter(Boolean).join(' · ')
                                    : 'O que o agente fez até aqui'
                            }
                            loading={loading && !full}
                        />
                    </div>
                </section>

                <section className="grid gap-2">
                    <Heading>Percurso</Heading>
                    <Timeline approval={approval} />
                </section>

                <Collapsible>
                    <CollapsibleTrigger className="flex items-center gap-1.5 text-xs text-muted-foreground hover:text-foreground [&[data-state=open]>svg:last-child]:rotate-90">
                        <Code2 className="size-3.5" />
                        Detalhes técnicos
                        <ChevronRight className="size-3.5 transition-transform" />
                    </CollapsibleTrigger>
                    <CollapsibleContent className="mt-2 grid gap-2">
                        <p className="text-xs text-muted-foreground">
                            Capacidade <code className="rounded bg-muted px-1 font-mono">{approval.action_type}</code> · execução{' '}
                            <span className="font-mono">#{approval.run_id}</span> · aprovação <span className="font-mono">#{approval.id}</span>
                        </p>
                        <pre className="max-h-56 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs">{JSON.stringify(approval.payload, null, 2)}</pre>
                    </CollapsibleContent>
                </Collapsible>
            </div>

            {decidable ? (
                <div className="flex shrink-0 flex-col gap-3 border-t bg-sidebar/40 px-5 py-4">
                    <div className="grid gap-1.5">
                        <label htmlFor={`panel-note-${approval.id}`} className="text-xs font-medium">
                            Nota <span className="font-normal text-muted-foreground">· opcional ao aprovar, obrigatória ao rejeitar</span>
                        </label>
                        <Textarea
                            id={`panel-note-${approval.id}`}
                            rows={2}
                            value={form.data.note}
                            onChange={(event) => form.setData('note', event.target.value)}
                            placeholder="O agente lê a nota e ajusta o que faz a seguir."
                            className="min-h-14 resize-none bg-background"
                        />
                        {form.errors.note && <p className="text-xs text-destructive">{form.errors.note}</p>}
                    </div>
                    <div className="grid grid-cols-2 gap-2 sm:flex sm:justify-end">
                        <Button variant="outline" disabled={form.processing || form.data.note.trim() === ''} onClick={() => decide('reject')}>
                            <X />
                            Rejeitar
                        </Button>
                        <Button disabled={form.processing} onClick={() => decide('approve')}>
                            {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                            Aprovar
                        </Button>
                    </div>
                </div>
            ) : (
                pending && (
                    <p className="shrink-0 border-t bg-sidebar/40 px-5 py-3 text-xs text-muted-foreground">
                        Só {approval.assigned_to ?? 'quem responde por este agente'} ou a direcção pode decidir esta acção.
                    </p>
                )
            )}
        </div>
    );
}

function Heading({ children }: { children: string }) {
    return <h3 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">{children}</h3>;
}

function NavButton({ icon: Icon, label, shortcut, onClick }: { icon: LucideIcon; label: string; shortcut: string; onClick: (() => void) | null }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button variant="ghost" size="icon-sm" className="text-muted-foreground" disabled={!onClick} onClick={onClick ?? undefined} aria-label={label}>
                    <Icon />
                </Button>
            </TooltipTrigger>
            <TooltipContent>
                {label} <kbd className="ml-1 rounded border px-1 font-mono text-[10px]">{shortcut}</kbd>
            </TooltipContent>
        </Tooltip>
    );
}

function ContextLink({ href, icon: Icon, title, meta, loading = false }: { href: string; icon: LucideIcon; title: string; meta: string; loading?: boolean }) {
    return (
        <Link href={href} className="group flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-accent/60">
            <span className="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="size-4" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-medium">{title}</span>
                <span className="block truncate text-xs text-muted-foreground">{loading ? 'A carregar…' : meta}</span>
            </span>
            <ArrowUpRight className="size-4 shrink-0 text-muted-foreground group-hover:text-foreground" />
        </Link>
    );
}

interface Event {
    icon: LucideIcon;
    tone: Tone;
    title: string;
    at?: string | null;
    note?: string | null;
}

/** Asked, revalidated, decided, carried out: what happened to it so far. */
function Timeline({ approval }: { approval: ApprovalRow }) {
    const events: Event[] = [
        {
            icon: CircleDot,
            tone: 'idle',
            title: `${approval.agent.name} pediu (está em N${approval.agent_level}, a acção pede N${approval.required_level})`,
            at: approval.created_at,
        },
    ];

    if (approval.review_stage === 'agent' && approval.status === 'pending') {
        events.push({ icon: Sparkles, tone: 'running', title: `${approval.review_agent ?? 'O Chief of Staff'} está a rever` });
    } else if (approval.review_agent && approval.review_stage === 'human') {
        events.push({ icon: Sparkles, tone: 'idle', title: `${approval.review_agent} reviu e passou a uma pessoa`, note: approval.review_note });
    }

    if (approval.status === 'pending') {
        events.push({ icon: CircleDot, tone: 'warning', title: `À espera de ${approval.assigned_to ?? 'uma pessoa'}` });
    } else if (approval.status === 'expired') {
        events.push({ icon: X, tone: 'idle', title: 'Expirou sem decisão', at: approval.decided_at });
    } else {
        events.push({
            icon: approval.status === 'approved' ? Check : X,
            tone: approval.status === 'approved' ? 'success' : 'danger',
            title: `${approval.status_label} por ${approval.decided_by ?? '—'}`,
            at: approval.decided_at,
            note: approval.decision_note,
        });
    }

    if (approval.status === 'approved') {
        events.push({
            icon: Play,
            tone: approval.execution_status === 'failed' ? 'danger' : approval.execution_status === 'executed' ? 'success' : 'running',
            title: approval.execution_status === 'not_executed' ? 'A executar…' : `Acção ${executionLabel[approval.execution_status]}`,
            at: approval.executed_at,
            note: approval.execution_status !== 'not_executed' ? approval.execution_result?.content : null,
        });
    }

    return (
        <ol className="relative grid gap-4 pl-1">
            {events.map((event, index) => (
                <li key={index} className="relative flex gap-3">
                    {index < events.length - 1 && <span className="absolute top-7 bottom-[-1rem] left-3 w-px bg-border" aria-hidden="true" />}
                    <span
                        className={cn(
                            'relative inline-flex size-6 shrink-0 items-center justify-center rounded-full border bg-background',
                            event.tone === 'success' && 'border-status-success/40 text-status-success',
                            event.tone === 'danger' && 'border-status-danger/40 text-status-danger',
                            event.tone === 'warning' && 'border-status-warning/50 text-status-warning',
                            event.tone === 'running' && 'border-primary/40 text-primary',
                            event.tone === 'idle' && 'text-muted-foreground',
                        )}
                    >
                        <event.icon className="size-3.5" />
                    </span>
                    <div className="min-w-0 flex-1 pt-0.5">
                        <p className="text-sm leading-snug">{event.title}</p>
                        {event.at && (
                            <p className="text-xs text-muted-foreground" title={dateTime(event.at)}>
                                {dateTime(event.at)}
                            </p>
                        )}
                        {event.note && <p className="mt-1.5 max-h-40 overflow-auto rounded-lg bg-muted px-3 py-2 text-xs whitespace-pre-wrap">{event.note}</p>}
                    </div>
                </li>
            ))}
        </ol>
    );
}
