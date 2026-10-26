import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowUp, Check, ChevronDown, Loader2 } from 'lucide-react';
import { type FormEvent, Fragment, useRef, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ApprovalSheet } from '@/Components/approvals/ApprovalSheet';
import { AttachButton, AttachmentTray, DropOverlay, RecordControl, useFileDrop } from '@/Components/attachments/Composer';
import { InputError } from '@/Components/InputError';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Textarea } from '@/Components/ui/textarea';
import { useAttachments } from '@/hooks/useAttachments';
import { useWide } from '@/hooks/useWide';
import AppLayout from '@/Layouts/AppLayout';
import type { AttachmentLimits } from '@/lib/attachments';
import type { ApprovalSummary, SharedProps } from '@/types';

interface HomeAgent {
    id: number;
    name: string;
    title: string | null;
    avatar_url: string | null;
    suggestions: string[];
}

const waitingLink = 'font-medium text-foreground underline-offset-4 hover:underline';

function greeting(now = new Date()) {
    const hour = now.getHours();

    return hour < 12 ? 'Bom dia' : hour < 19 ? 'Boa tarde' : 'Boa noite';
}

/**
 * The entry screen (docs/DECISOES.md, "Ecrã de entrada e modo lite"): a
 * greeting, one box to write in, the agent it goes to and what to ask it.
 * Sending opens (or starts) the person's conversation with that agent.
 */
export default function Home({
    agents,
    agent_id,
    questions,
    approvals,
    attachment_limits,
}: {
    agents: HomeAgent[];
    agent_id: number;
    questions: { agent: string; href: string }[];
    approvals: ApprovalSummary[];
    attachment_limits: AttachmentLimits;
}) {
    const { auth } = usePage<SharedProps>().props;
    const [agentId, setAgentId] = useState(agent_id);
    const agent = agents.find((candidate) => candidate.id === agentId) ?? agents[0];
    const form = useForm({ message: '', attachment_ids: [] as number[] });
    const files = useAttachments(attachment_limits, { agent_id: agent.id });
    const drop = useFileDrop(files.add);
    const box = useRef<HTMLTextAreaElement>(null);
    const canSend = !form.processing && !files.uploading && !files.failed && (form.data.message.trim() !== '' || files.ids.length > 0);
    const [openApproval, setOpenApproval] = useState<number | null>(null);
    // Beside the page on a wide screen (the page moves over for it), over it on a narrow one.
    const wide = useWide();
    const approvalSheet = <ApprovalSheet approvals={approvals} openId={openApproval} onOpenChange={setOpenApproval} docked={wide} />;
    const firstName = auth.user?.name.split(' ')[0] ?? '';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!canSend) {
            return;
        }
        form.transform((data) => ({ ...data, attachment_ids: files.ids }));
        form.post(`/agents/${agent.id}/chat`);
    };

    // What needs the person, in one line: the counters already in the sidebar, and the agents waiting on an answer.
    const waiting = [
        auth.pending_approvals > 0 && {
            key: 'approvals',
            href: '/approvals',
            // Opens the first in the side panel; the page itself only if the list could not come along.
            onClick: approvals.length > 0 ? () => setOpenApproval(approvals[0].id) : undefined,
            label: auth.pending_approvals === 1 ? '1 aprovação' : `${auth.pending_approvals} aprovações`,
        },
        auth.waiting_tasks > 0 && {
            key: 'tasks',
            href: '/tasks',
            label: auth.waiting_tasks === 1 ? '1 tarefa' : `${auth.waiting_tasks} tarefas`,
        },
        ...questions.map((question) => ({ key: question.href, href: question.href, label: `perguntas de ${question.agent}` })),
    ].filter((item): item is { key: string; href: string; label: string; onClick?: () => void } => Boolean(item));

    return (
        <AppLayout breadcrumbs={[{ label: 'Início' }]} aside={wide && openApproval !== null ? approvalSheet : undefined}>
            <Head title="Início" />

            <div className="mx-auto flex min-h-[calc(100svh-9rem)] w-full max-w-2xl flex-col items-center justify-center gap-6 py-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <h1 className="text-3xl font-medium tracking-tight sm:text-4xl">
                        {greeting()}
                        {firstName && (
                            <>
                                , <span className="text-primary">{firstName}</span>
                            </>
                        )}
                    </h1>
                    {waiting.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            À sua espera:{' '}
                            {waiting.map((item, index) => (
                                <Fragment key={item.key}>
                                    {index > 0 && ' · '}
                                    {item.onClick ? (
                                        <button type="button" onClick={item.onClick} className={waitingLink}>
                                            {item.label}
                                        </button>
                                    ) : (
                                        <Link href={item.href} className={waitingLink}>
                                            {item.label}
                                        </Link>
                                    )}
                                </Fragment>
                            ))}
                        </p>
                    )}
                </div>

                <form onSubmit={submit} {...drop.dropProps} className="relative flex w-full flex-col gap-2 rounded-2xl border bg-card p-3">
                    <DropOverlay show={drop.dragging} />
                    <div className="-mx-3 -mt-3">
                        <AttachmentTray items={files.items} onRemove={files.remove} notice={files.notice} onDismissNotice={files.dismissNotice} />
                    </div>
                    <Textarea
                        ref={box}
                        value={form.data.message}
                        onChange={(e) => form.setData('message', e.target.value)}
                        onPaste={drop.onPaste}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                submit(e);
                            }
                        }}
                        rows={3}
                        autoFocus
                        placeholder="Em que posso ajudar hoje?"
                        className="min-h-20 resize-none border-0 bg-transparent text-base shadow-none focus-visible:ring-0 dark:bg-transparent"
                        aria-label={`Mensagem para ${agent.name}`}
                    />
                    <InputError message={form.errors.message ?? form.errors.attachment_ids} />
                    <div className="flex items-center gap-2">
                        <AttachButton extensions={attachment_limits.extensions} onFiles={files.add} />
                        {attachment_limits.types.includes('audio') && (
                            <RecordControl maxSeconds={attachment_limits.audio_max_seconds} onRecorded={(file) => files.add([file], 'recording')} />
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button type="button" variant="outline" size="sm" className="min-w-0 gap-1.5" aria-label="Escolher o agente">
                                    <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-4 rounded-[5px] text-[8px]" />
                                    <span className="truncate">{agent.name}</span>
                                    <ChevronDown className="text-muted-foreground" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="max-h-80 w-72 overflow-y-auto">
                                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">Falar com</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {agents.map((candidate) => (
                                    <DropdownMenuItem
                                        key={candidate.id}
                                        onSelect={() => {
                                            setAgentId(candidate.id);
                                            box.current?.focus();
                                        }}
                                        className="gap-2"
                                    >
                                        <AgentAvatar name={candidate.name} url={candidate.avatar_url} className="size-6 rounded-md text-[10px]" />
                                        <span className="grid min-w-0 flex-1 leading-tight">
                                            <span className="truncate text-sm">{candidate.name}</span>
                                            {candidate.title && <span className="truncate text-xs text-muted-foreground">{candidate.title}</span>}
                                        </span>
                                        {candidate.id === agent.id && <Check className="text-primary" />}
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <Button type="submit" size="icon" className="ml-auto size-8 shrink-0 rounded-lg" disabled={!canSend} aria-label="Enviar">
                            {form.processing ? <Loader2 className="animate-spin" /> : <ArrowUp />}
                        </Button>
                    </div>
                </form>

                {agent.suggestions.length > 0 && (
                    <div className="flex flex-wrap justify-center gap-2">
                        {agent.suggestions.map((suggestion) => (
                            <button
                                key={suggestion}
                                type="button"
                                onClick={() => {
                                    form.setData('message', suggestion);
                                    box.current?.focus();
                                }}
                                className="rounded-full border bg-background px-3 py-1.5 text-sm text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            >
                                {suggestion}
                            </button>
                        ))}
                    </div>
                )}
            </div>

            {!wide && approvalSheet}
        </AppLayout>
    );
}
