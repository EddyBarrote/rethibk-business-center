/*
 * The email client (docs/DECISOES.md, "Email: um cliente de email na plataforma").
 */

import type { Tone } from '@/Components/Status';
import type { Option } from '@/types';

export interface EmailFolder {
    id: number;
    name: string;
    role: 'inbox' | 'drafts' | 'sent' | 'archive' | 'junk' | 'trash' | null;
    unread: number;
    total: number;
    has_older: boolean;
    depth: number;
}

export interface EmailMailbox {
    id: number;
    address: string;
    name: string;
    personal: boolean;
    imap: boolean;
    can_write: boolean;
    status: string;
    error: string | null;
    folders: EmailFolder[];
}

export interface EmailSummary {
    id: number;
    direction: 'inbound' | 'outbound';
    from: string | null;
    from_name: string | null;
    from_address: string | null;
    to_list: string | null;
    subject: string;
    snippet: string;
    summary: string | null;
    category: string | null;
    category_label: string | null;
    priority: string | null;
    status: string;
    status_label: string;
    mailbox: string | null;
    folder: string | null;
    folder_role: string | null;
    routed_to: string | null;
    deadline_at: string | null;
    injection: boolean;
    unread: boolean;
    flagged: boolean;
    attachments_count: number | null;
    date: string | null;
}

export interface EmailFile {
    id: number;
    name: string;
    mime: string;
    size: number;
    extension: string;
    available: boolean;
    url: string;
}

export interface ThreadMessage extends EmailSummary {
    to: string[];
    cc: string[];
    bcc: string[];
    reply_to: string | null;
    body: string;
    html: string | null;
    message_id: string | null;
    extracted: Record<string, unknown> | null;
    department: string | null;
    erp_lead_id: string | null;
    agent_run_id: number | null;
    written_by_agent: boolean;
    attachments: EmailFile[];
}

export interface OpenConversation {
    id: number;
    mailbox_id: number;
    subject: string;
    can_reply: boolean;
    can_file: boolean;
    messages: ThreadMessage[];
    tasks: { id: number; ref: string; title: string }[];
    follow_ups: { id: number; title: string; due_at: string; done: boolean }[];
    categories: Option[];
}

/** What the composer edits: a new email, a reply, a forward, or a draft. */
export interface Composition {
    draftId: number | null;
    mailboxId: number;
    replyToId: number | null;
    to: string;
    cc: string;
    bcc: string;
    subject: string;
    body: string;
    keep: EmailFile[];
    title: string;
}

export const roleOrder = ['inbox', 'drafts', 'sent', 'archive', 'junk', 'trash'];

/** Email lifecycle (EmailStatus) mapped to the shared tones. */
export const emailTone = (status: string): Tone =>
    (({
        received: 'idle',
        processing: 'running',
        processed: 'success',
        failed: 'danger',
        draft: 'warning',
        queued: 'running',
        sent: 'success',
        bounced: 'danger',
    })[status] as Tone) ?? 'idle';

export const addressList = (value: string) =>
    value
        .split(/[,;\s]+/)
        .map((a) => a.trim().replace(/^.*<(.+)>$/, '$1'))
        .filter(Boolean);

const prefixed = (subject: string, prefix: 'Re' | 'Enc') =>
    new RegExp(`^${prefix === 'Re' ? '(re|res)' : '(enc|fw|fwd)'}\\s*:`, 'i').test(subject.trim()) ? subject : `${prefix}: ${subject}`;

const when = (value: string | null) =>
    value ? new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '';

function quote(message: ThreadMessage) {
    const header = `Em ${when(message.date)}, ${message.from ?? message.from_address ?? ''} escreveu:`;

    return `\n\n${header}\n${message.body
        .split('\n')
        .map((line) => `> ${line}`)
        .join('\n')}`;
}

export function replyTo(message: ThreadMessage, mailbox: EmailMailbox | undefined, all: boolean): Composition {
    const own = (mailbox?.address ?? '').toLowerCase();
    const sender = message.direction === 'outbound' ? message.to : [message.reply_to ?? message.from_address ?? ''];
    const others = all ? [...message.to, ...message.cc].filter((a) => a.toLowerCase() !== own && !sender.includes(a)) : [];

    return {
        draftId: null,
        mailboxId: mailbox?.id ?? 0,
        replyToId: message.id,
        to: sender.filter(Boolean).join(', '),
        cc: [...new Set(others)].join(', '),
        bcc: '',
        subject: prefixed(message.subject, 'Re'),
        body: quote(message),
        keep: [],
        title: all ? 'Responder a todos' : 'Responder',
    };
}

export function forward(message: ThreadMessage, mailbox: EmailMailbox | undefined): Composition {
    const header = [
        '---------- Mensagem encaminhada ----------',
        `De: ${message.from ?? message.from_address ?? ''}`,
        `Data: ${when(message.date)}`,
        `Assunto: ${message.subject}`,
        `Para: ${message.to.join(', ')}`,
    ].join('\n');

    return {
        draftId: null,
        mailboxId: mailbox?.id ?? 0,
        replyToId: null,
        to: '',
        cc: '',
        bcc: '',
        subject: prefixed(message.subject, 'Enc'),
        body: `\n\n${header}\n\n${message.body}`,
        keep: message.attachments.filter((a) => a.available),
        title: 'Reencaminhar',
    };
}

export function fromDraft(draft: ThreadMessage, mailboxId: number, replyToId: number | null): Composition {
    return {
        draftId: draft.id,
        mailboxId,
        replyToId,
        to: draft.to.join(', '),
        cc: draft.cc.join(', '),
        bcc: draft.bcc.join(', '),
        subject: draft.subject === '(sem assunto)' ? '' : draft.subject,
        body: draft.body,
        keep: draft.attachments.filter((a) => a.available),
        title: draft.written_by_agent ? 'Rascunho do agente' : 'Rascunho',
    };
}

export function blank(mailboxId: number): Composition {
    return { draftId: null, mailboxId, replyToId: null, to: '', cc: '', bcc: '', subject: '', body: '', keep: [], title: 'Nova mensagem' };
}
