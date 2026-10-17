import type { RequestPayload } from '@inertiajs/core';
import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertOctagon,
    Archive,
    ArrowLeft,
    Check,
    ChevronLeft,
    ChevronRight,
    ChevronsUpDown,
    FileText,
    Folder,
    FolderInput,
    Forward,
    Inbox,
    Loader2,
    Mail,
    MailOpen,
    Paperclip,
    PenLine,
    Plus,
    RefreshCw,
    Reply,
    ReplyAll,
    Send,
    Settings,
    ShieldAlert,
    Star,
    Trash2,
    UserRound,
} from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';

import { AgentMark } from '@/Components/AgentAvatar';
import { MessageAttachments } from '@/Components/attachments/MessageAttachments';
import { Monogram } from '@/Components/Blocks';
import { CategoryBadge, PriorityDot } from '@/Components/CategoryBadge';
import { ConfirmDialog } from '@/Components/Dialogs';
import { EmailBody } from '@/Components/email/EmailBody';
import { EmailComposer } from '@/Components/email/EmailComposer';
import { EmailFilters, type EmailListFilters, filterParams, narrowed } from '@/Components/email/EmailFilters';
import { TalkToAgent } from '@/Components/email/TalkToAgent';
import { TriagePanel } from '@/Components/email/TriagePanel';
import { EmptyState } from '@/Components/EmptyState';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import type { AttachmentLimits, MessageAttachment } from '@/lib/attachments';
import {
    blank,
    type Composition,
    draftTitle,
    emailTone,
    type EmailFile,
    type EmailFolder,
    type EmailMailbox,
    type EmailSummary,
    forward,
    fromDraft,
    type OpenConversation,
    replyTo,
    type ThreadMessage,
} from '@/lib/email';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option } from '@/types';

interface Props {
    mailboxes: EmailMailbox[];
    routed: boolean;
    current: {
        mailbox: number | 'routed' | null;
        folder: number | null;
        folder_role: string | null;
        q: string;
        category: string | null;
        unread: boolean;
        triage: boolean;
    } & Omit<EmailListFilters, 'mailbox' | 'folder' | 'q' | 'category'>;
    messages: { data: EmailSummary[]; next: string | null; prev: string | null; from: number; to: number; total: number };
    categories: (Option & { count: number })[];
    triage_states: Option[];
    open: OpenConversation | null;
    attachment_limits: AttachmentLimits;
    can_connect: boolean;
    images_ask: boolean;
}

const folderIcons: Record<string, typeof Inbox> = {
    inbox: Inbox,
    drafts: FileText,
    sent: Send,
    archive: Archive,
    junk: AlertOctagon,
    trash: Trash2,
};

const visitOptions = { preserveState: true, preserveScroll: true } as const;

/** Files of an email in the shape of the conversations' viewer. */
const asViewerFiles = (files: EmailFile[]): MessageAttachment[] =>
    files
        .filter((file) => file.available)
        .map((file) => ({
            id: file.id,
            name: file.name,
            kind: file.mime.startsWith('image/')
                ? 'image'
                : file.mime.startsWith('audio/')
                  ? 'audio'
                  : file.mime.startsWith('video/')
                    ? 'video'
                    : file.mime === 'application/pdf'
                      ? 'pdf'
                      : file.mime.startsWith('text/')
                        ? 'text'
                        : 'document',
            mime: file.mime,
            size: file.size,
            extension: file.extension,
            source: 'upload',
            url: file.url,
            transcript: null,
        }));

function FolderButton({ folder, active, onClick }: { folder: EmailFolder; active: boolean; onClick: () => void }) {
    const Icon = (folder.role && folderIcons[folder.role]) || Folder;

    return (
        <button
            type="button"
            onClick={onClick}
            style={folder.depth > 0 ? { paddingLeft: `${0.5 + folder.depth * 0.875}rem` } : undefined}
            className={cn(
                'flex h-8 w-full items-center gap-2 rounded-lg px-2 text-sm transition-colors',
                active ? 'bg-accent font-medium text-foreground' : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
            )}
        >
            <Icon className="size-4 shrink-0" />
            <span className="min-w-0 flex-1 truncate text-left">{folder.name}</span>
            {folder.unread > 0 && folder.role !== 'sent' && folder.role !== 'trash' && (
                <span className={cn('font-mono text-[11px]', active ? 'text-foreground' : 'text-foreground/80')}>{folder.unread}</span>
            )}
        </button>
    );
}

/** How a mailbox is called in the menu: the person's name or the agent's. */
const mailboxLabel = (mailbox: EmailMailbox) => (mailbox.personal ? mailbox.name || mailbox.address : mailbox.name.replace(/^Triagem · /, ''));

const ROUTED_LABEL = 'Encaminhados para mim';

function MailboxIcon({ mailbox, className }: { mailbox: EmailMailbox | null; className?: string }) {
    const Icon = mailbox === null ? Forward : mailbox.personal ? UserRound : Inbox;

    return <Icon className={cn('size-4 shrink-0 text-muted-foreground', className)} />;
}

/**
 * Which mailbox the sidebar shows: one menu with each mailbox and its unread
 * count. With a single mailbox it is just its name.
 */
function MailboxPicker({ mailboxes, routed, current }: { mailboxes: EmailMailbox[]; routed: boolean; current: Props['current'] }) {
    const selected = mailboxes.find((m) => m.id === current.mailbox) ?? null;
    const personal = mailboxes.filter((m) => m.personal);
    const triage = mailboxes.filter((m) => !m.personal);
    const choices = mailboxes.length + (routed ? 1 : 0);
    const choose = (mailbox: number | 'routed') => router.get('/inbox', { mailbox }, visitOptions);

    const face = (
        <>
            <MailboxIcon mailbox={selected} />
            <span className="flex min-w-0 flex-1 flex-col text-left leading-tight">
                <span className="truncate text-sm font-medium">{selected ? mailboxLabel(selected) : ROUTED_LABEL}</span>
                {selected && <span className="truncate text-[11px] text-muted-foreground">{selected.personal ? selected.address : 'Triagem'}</span>}
            </span>
            {selected?.error && <span className="size-1.5 shrink-0 rounded-full bg-status-danger" title={selected.error} />}
        </>
    );

    if (choices <= 1) {
        return (
            <div className="flex items-center gap-2 px-2 py-1.5" title={selected?.address}>
                {face}
            </div>
        );
    }

    const item = (mailbox: EmailMailbox) => (
        <DropdownMenuItem
            key={mailbox.id}
            onSelect={() => choose(mailbox.id)}
            className={cn('gap-2', current.mailbox === mailbox.id && 'bg-accent/60')}
        >
            <MailboxIcon mailbox={mailbox} />
            <span className="flex min-w-0 flex-1 flex-col leading-tight">
                <span className={cn('truncate', current.mailbox === mailbox.id && 'font-medium')}>{mailboxLabel(mailbox)}</span>
                <span className="truncate text-[11px] text-muted-foreground">{mailbox.address}</span>
            </span>
            {mailbox.error && <span className="size-1.5 shrink-0 rounded-full bg-status-danger" title={mailbox.error} />}
            {mailbox.unread > 0 && <span className="font-mono text-[11px] text-foreground/80">{mailbox.unread}</span>}
            {current.mailbox === mailbox.id && <Check className="size-3.5 shrink-0" />}
        </DropdownMenuItem>
    );

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    aria-label="Escolher a caixa de email"
                    className="flex w-full items-center gap-2 rounded-lg border bg-background px-2 py-1.5 transition-colors hover:bg-accent/60 data-[state=open]:bg-accent/60"
                >
                    {face}
                    {selected && selected.unread > 0 && <span className="font-mono text-[11px] text-foreground/80">{selected.unread}</span>}
                    <ChevronsUpDown className="size-3.5 shrink-0 text-muted-foreground" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-72 max-w-[calc(100vw-2rem)]">
                {personal.length > 0 && (
                    <DropdownMenuGroup>
                        <DropdownMenuLabel className="text-[11px] text-muted-foreground">
                            {personal.length > 1 ? 'As minhas caixas' : 'A minha caixa'}
                        </DropdownMenuLabel>
                        {personal.map(item)}
                    </DropdownMenuGroup>
                )}
                {personal.length > 0 && triage.length > 0 && <DropdownMenuSeparator />}
                {triage.length > 0 && (
                    <DropdownMenuGroup>
                        <DropdownMenuLabel className="text-[11px] text-muted-foreground">Triagem</DropdownMenuLabel>
                        {triage.map(item)}
                    </DropdownMenuGroup>
                )}
                {routed && (
                    <>
                        {mailboxes.length > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuItem onSelect={() => choose('routed')} className="gap-2">
                            <MailboxIcon mailbox={null} />
                            <span className={cn('flex-1', current.mailbox === 'routed' && 'font-medium')}>{ROUTED_LABEL}</span>
                            {current.mailbox === 'routed' && <Check className="size-3.5 shrink-0" />}
                        </DropdownMenuItem>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function MailboxNav({
    mailboxes,
    routed,
    current,
    categories,
    canConnect,
    onCompose,
    canCompose,
}: {
    mailboxes: EmailMailbox[];
    routed: boolean;
    current: Props['current'];
    categories: Props['categories'];
    canConnect: boolean;
    onCompose: () => void;
    canCompose: boolean;
}) {
    const go = (params: RequestPayload) => router.get('/inbox', params, visitOptions);
    const mailbox = mailboxes.find((m) => m.id === current.mailbox);

    return (
        <nav aria-label="Caixas e pastas" className="flex h-full min-h-0 flex-col gap-3 overflow-y-auto p-3">
            <MailboxPicker mailboxes={mailboxes} routed={routed} current={current} />

            <Button
                onClick={onCompose}
                disabled={!canCompose}
                className="w-full justify-start"
                title={canCompose ? undefined : 'Nenhuma das suas caixas pode enviar'}
            >
                <PenLine />
                Escrever
            </Button>

            {mailbox && (
                <div className="flex flex-col gap-0.5">
                    {mailbox.folders.map((folder) => (
                        <FolderButton
                            key={folder.id}
                            folder={folder}
                            active={current.folder === folder.id && !current.em}
                            onClick={() => go({ mailbox: mailbox.id, folder: folder.id })}
                        />
                    ))}
                    {mailbox.folders.length === 0 && <p className="px-2 text-xs text-muted-foreground">{mailbox.error ?? 'Sem pastas ainda.'}</p>}
                    {!mailbox.personal && categories.some((c) => c.count > 0) && (
                        <div className="mt-2 flex flex-col gap-0.5">
                            <p className="px-2 pt-1 pb-0.5 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">Triagem</p>
                            {categories
                                .filter((c) => c.count > 0)
                                .map((c) => (
                                    <button
                                        key={c.value}
                                        type="button"
                                        onClick={() =>
                                            go({
                                                mailbox: mailbox.id,
                                                folder: current.folder,
                                                category: current.category === c.value ? undefined : c.value,
                                            })
                                        }
                                        className={cn(
                                            'flex h-7 items-center gap-2 rounded-md px-2 text-xs',
                                            current.category === c.value
                                                ? 'bg-accent font-medium'
                                                : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                                        )}
                                    >
                                        <span className="min-w-0 flex-1 truncate text-left">{c.label}</span>
                                        <span className="font-mono text-[11px]">{c.count}</span>
                                    </button>
                                ))}
                        </div>
                    )}
                </div>
            )}

            {canConnect && (
                <Link
                    href="/settings/mailboxes"
                    className="mt-auto flex items-center gap-2 rounded-lg px-2 py-2 text-xs text-muted-foreground hover:bg-accent/60 hover:text-foreground"
                >
                    <Settings className="size-3.5" />
                    Ligar ou configurar caixas
                </Link>
            )}
        </nav>
    );
}

function MessageRow({ message, active, showFolder, onOpen }: { message: EmailSummary; active: boolean; showFolder: boolean; onOpen: () => void }) {
    const outbound = message.direction === 'outbound';
    const who = outbound ? `Para: ${message.to_list || '—'}` : (message.from_name ?? '—');

    return (
        <li>
            <button
                type="button"
                onClick={onOpen}
                className={cn(
                    'flex w-full flex-col gap-0.5 border-b px-4 py-2.5 text-left transition-colors',
                    active ? 'bg-accent' : 'hover:bg-accent/50',
                    message.unread && !active && 'bg-primary/[0.03]',
                )}
            >
                <span className="flex items-center gap-2">
                    {message.unread && <span className="size-2 shrink-0 rounded-full bg-primary" aria-label="Não lido" />}
                    <span className={cn('min-w-0 flex-1 truncate text-sm', message.unread ? 'font-semibold' : 'text-foreground/90')}>{who}</span>
                    {message.flagged && <Star className="size-3.5 shrink-0 fill-amber-400 text-amber-400" aria-label="Com estrela" />}
                    {(message.attachments_count ?? 0) > 0 && (
                        <Paperclip className="size-3.5 shrink-0 text-muted-foreground" aria-label="Com anexos" />
                    )}
                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums" title={dateTime(message.date)}>
                        {ago(message.date)}
                    </span>
                </span>
                <span className="flex items-center gap-1.5">
                    <PriorityDot priority={message.priority} />
                    {message.injection && <ShieldAlert className="size-3.5 shrink-0 text-status-danger" aria-label="Possível manipulação" />}
                    <span className={cn('min-w-0 flex-1 truncate text-sm', message.unread ? 'font-medium' : 'text-foreground/85')}>
                        {message.subject}
                    </span>
                    {message.status === 'draft' && <StatusBadge tone="warning">Rascunho</StatusBadge>}
                    {message.category && <CategoryBadge category={message.category} label={message.category_label} />}
                </span>
                <span className="line-clamp-1 text-xs text-muted-foreground">
                    {showFolder && message.folder && <span className="mr-1.5 rounded bg-muted px-1 py-px text-[10px]">{message.folder}</span>}
                    {message.summary ?? message.snippet}
                </span>
            </button>
        </li>
    );
}

function ThreadItem({
    message,
    expanded,
    onToggle,
    onEditDraft,
}: {
    message: ThreadMessage;
    expanded: boolean;
    onToggle: () => void;
    onEditDraft: () => void;
}) {
    const { images_ask } = usePage<{ images_ask: boolean }>().props;
    const outbound = message.direction === 'outbound';
    const draft = message.status === 'draft';
    const name = outbound ? (message.from_name ?? message.mailbox ?? '') : (message.from_name ?? message.from_address ?? '?');

    if (draft) {
        return (
            <article className="rounded-xl border border-status-warning/40 bg-status-warning/5">
                <div className="flex items-center gap-3 px-4 py-3">
                    {message.agent ? (
                        <AgentMark name={message.agent.name} url={message.agent.avatar_url} className="size-7" />
                    ) : (
                        <PenLine className="size-4 shrink-0 text-status-warning" />
                    )}
                    <div className="min-w-0 flex-1">
                        <p className="text-sm font-medium">{draftTitle(message)}</p>
                        <p className="truncate text-xs text-muted-foreground">
                            Para: {message.to.join(', ') || '—'} · {message.body.split('\n')[0]}
                        </p>
                    </div>
                    {message.proposal && (
                        <>
                            <ConfirmDialog
                                title="Descartar a proposta?"
                                description="O rascunho do agente deixa de aparecer neste email e na conversa."
                                confirmLabel="Descartar"
                                destructive
                                onConfirm={() => router.delete(`/inbox/${message.id}`, visitOptions)}
                                trigger={
                                    <Button size="sm" variant="ghost">
                                        Descartar
                                    </Button>
                                }
                            />
                            <Button size="sm" variant="outline" onClick={() => router.post(`/inbox/drafts/${message.id}/keep`, {}, visitOptions)}>
                                Guardar em rascunho
                            </Button>
                        </>
                    )}
                    <Button size="sm" onClick={onEditDraft}>
                        Rever e enviar
                    </Button>
                </div>
            </article>
        );
    }

    return (
        <article className={cn('rounded-xl border bg-card', outbound && 'bg-accent/20')}>
            <button type="button" onClick={onToggle} className="flex w-full items-start gap-3 px-4 py-3 text-left">
                <Monogram name={name} />
                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-baseline gap-x-2">
                        <span className="truncate text-sm font-medium">{name}</span>
                        {message.from_address && message.from_address !== name && (
                            <span className="truncate text-xs text-muted-foreground">&lt;{message.from_address}&gt;</span>
                        )}
                    </div>
                    <p className="truncate text-xs text-muted-foreground">
                        {expanded ? (
                            <>
                                Para: {message.to.join(', ') || '—'}
                                {message.cc.length > 0 && ` · Cc: ${message.cc.join(', ')}`}
                            </>
                        ) : (
                            message.snippet
                        )}
                    </p>
                </div>
                <span className="shrink-0 text-xs whitespace-nowrap text-muted-foreground" title={dateTime(message.date)}>
                    {ago(message.date)}
                </span>
            </button>
            {expanded && (
                <div className="flex flex-col gap-4 px-4 pb-4">
                    <EmailBody html={message.html} text={message.body} images={!message.junk && !images_ask} />
                    {message.attachments.length > 0 && (
                        <>
                            <MessageAttachments attachments={asViewerFiles(message.attachments)} />
                            {message.attachments
                                .filter((file) => !file.available)
                                .map((file) => (
                                    <span
                                        key={file.id}
                                        className="inline-flex h-7 w-fit items-center rounded-md border border-dashed px-2 text-xs text-muted-foreground line-through"
                                        title="Apagado pela retenção ou grande demais"
                                    >
                                        {file.name}
                                    </span>
                                ))}
                        </>
                    )}
                </div>
            )}
        </article>
    );
}

function Toolbar({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap items-center gap-1 border-b px-3 py-2">{children}</div>;
}

function ReadingPane({
    open,
    mailbox,
    folders,
    onCompose,
    onBack,
}: {
    open: OpenConversation;
    mailbox: EmailMailbox | undefined;
    folders: EmailFolder[];
    onCompose: (composition: Composition) => void;
    onBack: () => void;
}) {
    const target = open.messages.find((m) => m.id === open.id) ?? open.messages[open.messages.length - 1];
    const latest = [...open.messages].reverse().find((m) => m.status !== 'draft') ?? target;
    const [expanded, setExpanded] = useState<Set<number>>(() => new Set([target?.id, latest?.id].filter(Boolean) as number[]));
    const action = (path: string, data: RequestPayload = {}) => router.post(`/inbox/${target.id}/${path}`, data, visitOptions);
    // Filing it away closes it, like any email client.
    const fileAway = (path: string, data: RequestPayload = {}) =>
        router.post(`/inbox/${target.id}/${path}`, data, { ...visitOptions, onSuccess: onBack });
    const remove = () => router.delete(`/inbox/${target.id}`, { ...visitOptions, onSuccess: onBack });

    useEffect(() => setExpanded(new Set([target?.id, latest?.id].filter(Boolean) as number[])), [open.id]); // eslint-disable-line react-hooks/exhaustive-deps

    const inTrash = target.folder_role === 'trash' || target.folder_role === 'junk';

    return (
        <div className="flex h-full min-h-0 flex-col">
            <Toolbar>
                <Button variant="ghost" size="icon" className="size-8 xl:hidden" onClick={onBack} aria-label="Voltar à lista">
                    <ArrowLeft />
                </Button>
                {open.can_reply && (
                    <>
                        <Button variant="ghost" size="sm" onClick={() => onCompose(replyTo(latest, mailbox, false))}>
                            <Reply />
                            Responder
                        </Button>
                        <Button variant="ghost" size="sm" className="hidden sm:inline-flex" onClick={() => onCompose(replyTo(latest, mailbox, true))}>
                            <ReplyAll />
                            Todos
                        </Button>
                        <Button variant="ghost" size="sm" onClick={() => onCompose(forward(latest, mailbox))}>
                            <Forward />
                            Reencaminhar
                        </Button>
                    </>
                )}
                <TalkToAgent emailId={target.id} subject={open.subject} />
                <span className="flex-1" />
                {open.can_file && (
                    <>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={() => action('flag', { flagged: !target.flagged })}
                            aria-label={target.flagged ? 'Tirar estrela' : 'Pôr estrela'}
                            title={target.flagged ? 'Tirar estrela' : 'Pôr estrela'}
                        >
                            <Star className={cn(target.flagged && 'fill-amber-400 text-amber-400')} />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            onClick={() => fileAway('read', { read: false })}
                            aria-label="Marcar como não lido"
                            title="Marcar como não lido"
                        >
                            <Mail />
                        </Button>
                        {target.folder_role !== 'archive' && (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                onClick={() => fileAway('archive')}
                                aria-label="Arquivar"
                                title="Arquivar"
                            >
                                <Archive />
                            </Button>
                        )}
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="size-8" aria-label="Mover para" title="Mover para">
                                    <FolderInput />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="max-h-80 overflow-y-auto">
                                <DropdownMenuLabel>Mover para</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {folders
                                    .filter((f) => f.role !== 'drafts' && f.role !== 'sent' && f.name !== target.folder)
                                    .map((f) => {
                                        const Icon = (f.role && folderIcons[f.role]) || Folder;

                                        return (
                                            <DropdownMenuItem key={f.id} onSelect={() => fileAway('move', { folder_id: f.id })}>
                                                <Icon />
                                                {f.name}
                                            </DropdownMenuItem>
                                        );
                                    })}
                            </DropdownMenuContent>
                        </DropdownMenu>
                        {inTrash ? (
                            <ConfirmDialog
                                title="Apagar para sempre?"
                                description="Sai do servidor de email e não se pode recuperar."
                                confirmLabel="Apagar"
                                destructive
                                onConfirm={remove}
                                trigger={
                                    <Button variant="ghost" size="icon" className="size-8" aria-label="Apagar para sempre" title="Apagar para sempre">
                                        <Trash2 />
                                    </Button>
                                }
                            />
                        ) : (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                onClick={remove}
                                aria-label="Mover para o Lixo"
                                title="Mover para o Lixo"
                            >
                                <Trash2 />
                            </Button>
                        )}
                    </>
                )}
            </Toolbar>

            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4">
                <h2 className="text-lg leading-snug font-semibold text-balance">{open.subject}</h2>

                {target.injection && (
                    <div className="flex items-start gap-3 rounded-xl border border-status-danger/30 bg-status-danger/8 px-4 py-3 text-sm">
                        <ShieldAlert className="mt-0.5 size-4 shrink-0 text-status-danger" />
                        <p>
                            <span className="font-medium text-status-danger">Possível tentativa de manipulação do agente.</span>{' '}
                            <span className="text-muted-foreground">Leia com cuidado antes de agir sobre este email.</span>
                        </p>
                    </div>
                )}

                {(target.category || target.summary || open.tasks.length > 0) && <TriagePanel open={open} message={target} />}

                {open.messages.map((m) => (
                    <ThreadItem
                        key={m.id}
                        message={m}
                        expanded={expanded.has(m.id)}
                        onToggle={() =>
                            setExpanded((current) => {
                                const next = new Set(current);
                                if (next.has(m.id)) {
                                    next.delete(m.id);
                                } else {
                                    next.add(m.id);
                                }
                                return next;
                            })
                        }
                        onEditDraft={() => onCompose(fromDraft(m, open.mailbox_id, null))}
                    />
                ))}

                {open.can_reply && latest && latest.status !== 'draft' && (
                    <div className="flex flex-wrap gap-2 pb-2">
                        <Button variant="outline" size="sm" onClick={() => onCompose(replyTo(latest, mailbox, false))}>
                            <Reply />
                            Responder
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => onCompose(replyTo(latest, mailbox, true))}>
                            <ReplyAll />
                            Responder a todos
                        </Button>
                        <Button variant="outline" size="sm" onClick={() => onCompose(forward(latest, mailbox))}>
                            <Forward />
                            Reencaminhar
                        </Button>
                    </div>
                )}
            </div>
        </div>
    );
}

export default function EmailIndex({ mailboxes, routed, current, messages, categories, triage_states, open, attachment_limits, can_connect }: Props) {
    const [composing, setComposing] = useState<Composition | null>(null);
    // «Rever e enviar» on an email card in a conversation opens the draft straight in the composer.
    useEffect(() => {
        const id = Number(new URLSearchParams(window.location.search).get('rever'));
        const draft = open?.messages.find((m) => m.id === id && m.status === 'draft');
        if (open && draft) {
            setComposing(fromDraft(draft, open.mailbox_id, null));
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps
    const [syncing, setSyncing] = useState(false);
    // The row clicked shows as open at once, while the email loads.
    const [opening, setOpening] = useState<number | null>(null);
    const mailbox = mailboxes.find((m) => m.id === current.mailbox);
    const folder = mailbox?.folders.find((f) => f.id === current.folder);
    const writable = mailboxes.filter((m) => m.can_write);
    const filters: EmailListFilters = useMemo(
        () => ({
            mailbox: current.mailbox,
            folder: current.folder,
            q: current.q,
            category: current.category,
            em: current.em,
            leitura: current.leitura,
            anexos: current.anexos,
            estrela: current.estrela,
            periodo: current.periodo,
            de: current.de,
            ate: current.ate,
            triagem: current.triagem,
        }),
        [current],
    );
    const base = useMemo(() => filterParams(filters), [filters]);
    const filtering = current.q !== '' || narrowed(filters);

    const openMessage = (summary: EmailSummary) => {
        setComposing(null);
        setOpening(summary.id);
        const done = { onFinish: () => setOpening(null) };

        if (summary.status === 'draft') {
            // A draft opens straight in the composer.
            router.get(
                '/inbox',
                { ...base, message: summary.id },
                {
                    ...visitOptions,
                    ...done,
                    only: ['open', 'mailboxes', 'messages'],
                    onSuccess: (visit) => {
                        const conversation = visit.props.open as OpenConversation | null;
                        const draft = conversation?.messages.find((m) => m.id === summary.id);
                        if (conversation && draft) {
                            setComposing(fromDraft(draft, conversation.mailbox_id, null));
                        }
                    },
                },
            );

            return;
        }

        router.get('/inbox', { ...base, message: summary.id }, { ...visitOptions, ...done, only: ['open', 'mailboxes', 'messages'] });
    };

    const refresh = () => {
        if (typeof current.mailbox !== 'number' || !mailbox?.imap) {
            router.reload();
            return;
        }

        setSyncing(true);
        router.post(
            '/inbox/sync',
            { mailbox_id: current.mailbox, folder_id: current.folder },
            { ...visitOptions, onFinish: () => setSyncing(false) },
        );
    };

    // Every two minutes the open folder is read again from the server.
    useEffect(() => {
        if (typeof current.mailbox !== 'number' || !mailbox?.imap) {
            return;
        }

        const timer = window.setInterval(() => {
            if (!composing && document.visibilityState === 'visible') {
                router.post(
                    '/inbox/sync',
                    { mailbox_id: current.mailbox, folder_id: current.folder },
                    { ...visitOptions, only: ['mailboxes', 'messages'] },
                );
            }
        }, 120_000);

        return () => window.clearInterval(timer);
    }, [current.mailbox, current.folder, mailbox?.imap, composing]);

    const startCompose = () => setComposing(blank((mailbox?.can_write ? mailbox : writable[0])?.id ?? 0));
    const showingRight = composing !== null || open !== null || opening !== null;

    if (mailboxes.length === 0 && !routed) {
        return (
            <AppLayout breadcrumbs={[{ label: 'Email' }]}>
                <Head title="Email" />
                <EmptyState
                    icon={Mail}
                    title="Ainda não tem uma caixa de email ligada"
                    description="Ligue a sua caixa (o mesmo email e palavra-passe que usa no Outlook ou no telemóvel) para ler, escrever e enviar daqui, e escolher que agentes a podem ler."
                    action={
                        can_connect ? (
                            <Button asChild>
                                <Link href="/settings/mailboxes">
                                    <Plus />
                                    Ligar a minha caixa
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />
            </AppLayout>
        );
    }

    return (
        <AppLayout
            breadcrumbs={[
                { label: 'Email' },
                ...(mailbox ? [{ label: mailbox.personal ? mailbox.address : mailbox.name }] : []),
                ...(folder ? [{ label: folder.name }] : []),
            ]}
        >
            <Head title={folder ? `${folder.name} · Email` : 'Email'} />

            <div className="-mx-4 -my-6 grid h-[calc(100svh-3.5rem)] min-h-[32rem] grid-cols-1 overflow-hidden border-t sm:-mx-6 md:grid-cols-[13rem_minmax(0,1fr)] lg:-mx-8 xl:grid-cols-[14rem_minmax(20rem,26rem)_minmax(0,1fr)]">
                {/* Mailboxes and folders */}
                <aside className="hidden min-h-0 border-r bg-sidebar/40 md:block">
                    <MailboxNav
                        mailboxes={mailboxes}
                        routed={routed}
                        current={current}
                        categories={categories}
                        canConnect={can_connect}
                        onCompose={startCompose}
                        canCompose={writable.length > 0}
                    />
                </aside>

                {/* The list */}
                <section className={cn('flex min-h-0 flex-col border-r', showingRight && 'hidden xl:flex')} aria-label="Mensagens">
                    <div className="flex flex-col gap-2 border-b p-3">
                        <div className="flex items-center gap-2 md:hidden">
                            {mailboxes.length + (routed ? 1 : 0) > 1 && (
                                <NativeSelect
                                    aria-label="Caixa"
                                    className="h-9 min-w-0 flex-1"
                                    value={String(current.mailbox ?? '')}
                                    onChange={(e) => router.get('/inbox', { mailbox: e.target.value }, visitOptions)}
                                >
                                    {mailboxes.map((m) => (
                                        <option key={m.id} value={m.id}>
                                            {m.personal ? m.address : m.name}
                                            {m.unread > 0 ? ` (${m.unread})` : ''}
                                        </option>
                                    ))}
                                    {routed && <option value="routed">{ROUTED_LABEL}</option>}
                                </NativeSelect>
                            )}
                            {mailbox && mailbox.folders.length > 0 && (
                                <NativeSelect
                                    aria-label="Pasta"
                                    className="h-9 min-w-0 flex-1"
                                    value={String(current.folder ?? '')}
                                    onChange={(e) => router.get('/inbox', { mailbox: mailbox.id, folder: e.target.value }, visitOptions)}
                                >
                                    {mailbox.folders.map((f) => (
                                        <option key={f.id} value={f.id}>
                                            {f.name}
                                            {f.unread > 0 ? ` (${f.unread})` : ''}
                                        </option>
                                    ))}
                                </NativeSelect>
                            )}
                            <Button size="icon" className="size-9" onClick={startCompose} disabled={writable.length === 0} aria-label="Escrever">
                                <PenLine />
                            </Button>
                        </div>
                        <EmailFilters
                            filters={filters}
                            folderName={folder?.name ?? null}
                            placeholder={
                                current.em || !folder
                                    ? `Pesquisar em ${mailbox ? (mailbox.personal ? mailbox.address : mailbox.name) : 'Encaminhados'}`
                                    : `Pesquisar em ${folder.name}`
                            }
                            categories={categories}
                            triageStates={triage_states}
                        />
                        <div className="flex items-center gap-1 text-xs text-muted-foreground">
                            <span className="min-w-0 flex-1 truncate">
                                {current.q
                                    ? `Resultados para «${current.q}»`
                                    : current.em
                                      ? 'Todas as pastas'
                                      : (folder?.name ?? (current.mailbox === 'routed' ? 'Encaminhados para mim' : ''))}
                                {messages.total > 0 && ` · ${messages.from}–${messages.to} de ${messages.total}`}
                            </span>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                onClick={refresh}
                                disabled={syncing}
                                aria-label="Actualizar"
                                title="Ir buscar emails novos"
                            >
                                {syncing ? <Loader2 className="animate-spin" /> : <RefreshCw />}
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                disabled={!messages.prev}
                                onClick={() => messages.prev && router.get(messages.prev, {}, visitOptions)}
                                aria-label="Mais recentes"
                            >
                                <ChevronLeft />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-7"
                                disabled={!messages.next}
                                onClick={() => messages.next && router.get(messages.next, {}, visitOptions)}
                                aria-label="Mais antigos"
                            >
                                <ChevronRight />
                            </Button>
                        </div>
                        {mailbox?.error && (
                            <p className="rounded-lg border border-status-danger/30 bg-status-danger/8 px-3 py-2 text-xs">
                                {mailbox.error}{' '}
                                {mailbox.personal && (
                                    <Link href="/settings/mailboxes" className="font-medium underline">
                                        Rever a ligação
                                    </Link>
                                )}
                            </p>
                        )}
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {messages.data.length === 0 ? (
                            <div className="flex flex-col items-center gap-2 px-6 py-16 text-center text-sm text-muted-foreground">
                                <MailOpen className="size-6" />
                                {filtering ? (
                                    <>
                                        <p className="font-medium text-foreground">Nenhum email encontrado</p>
                                        <p className="text-xs">
                                            {current.em
                                                ? 'Experimente outra palavra ou retire filtros.'
                                                : 'Experimente outra palavra, retire filtros ou procure em todas as pastas.'}
                                        </p>
                                        {!current.em && typeof current.mailbox === 'number' && (
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                className="mt-1"
                                                onClick={() => router.get('/inbox', { ...base, em: 'todas' }, visitOptions)}
                                            >
                                                Procurar em todas as pastas
                                            </Button>
                                        )}
                                    </>
                                ) : (
                                    'Esta pasta está vazia.'
                                )}
                            </div>
                        ) : (
                            <ul>
                                {messages.data.map((m) => (
                                    <MessageRow
                                        key={m.id}
                                        message={m}
                                        active={(opening ?? open?.id) === m.id}
                                        showFolder={Boolean(current.em) || current.mailbox === 'routed'}
                                        onOpen={() => openMessage(m)}
                                    />
                                ))}
                            </ul>
                        )}
                        {folder?.has_older && !messages.next && !filtering && (
                            <div className="p-3">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="w-full"
                                    onClick={() => router.post(`/inbox/folders/${folder.id}/older`, {}, visitOptions)}
                                >
                                    Carregar mais antigos do servidor
                                </Button>
                            </div>
                        )}
                    </div>
                </section>

                {/* Reading or writing */}
                <section
                    className={cn('min-h-0 min-w-0', !showingRight && 'hidden xl:block', showingRight && 'md:col-start-2 xl:col-start-3')}
                    aria-label="Leitura"
                >
                    {opening !== null && !composing && open?.id !== opening ? (
                        <div className="flex h-full items-center justify-center text-muted-foreground">
                            <Loader2 className="size-5 animate-spin" aria-label="A abrir o email" />
                        </div>
                    ) : composing ? (
                        <EmailComposer
                            key={`${composing.draftId ?? 'new'}-${composing.replyToId ?? ''}-${composing.title}`}
                            initial={composing}
                            mailboxes={mailboxes}
                            limits={attachment_limits}
                            returnTo={{ folder: current.folder, message: open?.id ?? null }}
                            onClose={() => setComposing(null)}
                        />
                    ) : open ? (
                        <ReadingPane
                            open={open}
                            mailbox={mailboxes.find((m) => m.id === open.mailbox_id)}
                            folders={mailboxes.find((m) => m.id === open.mailbox_id)?.folders ?? []}
                            onCompose={setComposing}
                            onBack={() => router.get('/inbox', base, visitOptions)}
                        />
                    ) : (
                        <div className="flex h-full flex-col items-center justify-center gap-2 p-8 text-center text-sm text-muted-foreground">
                            <Mail className="size-8 text-muted-foreground/60" />
                            <p>Escolha um email para o ler.</p>
                            {writable.length > 0 && (
                                <Button variant="outline" size="sm" className="mt-2" onClick={startCompose}>
                                    <PenLine />
                                    Escrever um email
                                </Button>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}

export { emailTone };
