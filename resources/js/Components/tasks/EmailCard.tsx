import { Link, router } from '@inertiajs/react';
import { AlertTriangle, Check, Copy, Mail, Paperclip, PenLine, Save, Send, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { AgentMark } from '@/Components/AgentAvatar';
import { ConfirmDialog } from '@/Components/Dialogs';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Textarea } from '@/Components/ui/textarea';
import { time } from '@/lib/format';
import { cn } from '@/lib/utils';

/*
 * An email an agent proposes, shown in the conversation as an email, as in
 * Claude (docs/DECISOES.md, "Email proposto na conversa"). It is the draft in
 * Email, the same one the inbox shows: edited here or there, sent only by a
 * person, from Email. It goes to the Rascunhos only when the person keeps it
 * (docs/DECISOES.md, "Rascunho só quando a pessoa escolhe").
 */

export interface ProposedEmail {
    id: number;
    status: 'draft' | 'sent' | string;
    /** The agent that wrote it. */
    agent: { name: string; avatar_url: string | null } | null;
    /** Written by the colleague the request was passed to. */
    from_colleague: boolean;
    /** Not in the Rascunhos until the person keeps it (docs/DECISOES.md, "Rascunho só quando a pessoa escolhe"). */
    proposal: boolean;
    mailbox_id: number;
    from: string | null;
    to: string[];
    cc: string[];
    /** Who it is for when no source had the address. */
    pending_recipient: string | null;
    subject: string;
    body: string;
    attachments: { id: number; name: string }[];
    sent_at: string | null;
    can_open: boolean;
    can_edit: boolean;
}

const list = (value: string) =>
    value
        .split(/[,;\s]+/)
        .map((address) => address.trim())
        .filter(Boolean);

export function EmailCards({ emails }: { emails: ProposedEmail[] }) {
    if (emails.length === 0) {
        return null;
    }

    return (
        <div className="flex w-full flex-col gap-2">
            {emails.map((email) => (
                <EmailCard key={email.id} email={email} />
            ))}
        </div>
    );
}

function EmailCard({ email }: { email: ProposedEmail }) {
    const [editing, setEditing] = useState(false);
    const [copied, setCopied] = useState(false);
    const sent = email.status === 'sent';
    const draft = email.status === 'draft';
    const title = email.agent ? `Rascunho de ${email.agent.name}` : draft ? 'Rascunho' : 'Email';

    const [keeping, setKeeping] = useState(false);
    const keep = () => {
        setKeeping(true);
        router.post(`/inbox/drafts/${email.id}/keep`, {}, { preserveScroll: true, preserveState: true, onFinish: () => setKeeping(false) });
    };
    const discard = () => router.delete(`/inbox/${email.id}`, { preserveScroll: true, preserveState: true });

    // Sent from here, without leaving the conversation (docs/DECISOES.md, "Enviar a partir da conversa").
    const [sending, setSending] = useState(false);
    const [justSent, setJustSent] = useState(false);
    const [sendError, setSendError] = useState<string | null>(null);
    const send = () => {
        setSending(true);
        setSendError(null);
        router.post(
            `/inbox/${email.id}/send`,
            {
                mailbox_id: email.mailbox_id,
                to: email.to,
                cc: email.cc,
                subject: email.subject,
                body: email.body,
                keep: email.attachments.map((file) => file.id),
                stay: true,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: (page) => {
                    if (!(page.props.errors as Record<string, string> | undefined)?.send) {
                        setJustSent(true);
                    }
                },
                onError: (errors) => setSendError(errors.send ?? Object.values(errors)[0] ?? 'Não foi enviado.'),
                onFinish: () => setSending(false),
            },
        );
    };

    const copy = () => {
        void navigator.clipboard.writeText(`Assunto: ${email.subject}\n\n${email.body}`);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <article
            className={cn(
                'relative w-full max-w-2xl overflow-hidden rounded-xl border bg-card text-sm shadow-xs transition-colors',
                sending && 'border-primary/40',
                justSent && sent && 'border-status-success/40',
            )}
        >
            {/* While it goes: a bar runs along the top of the card. */}
            {sending && <span className="email-sending absolute inset-x-0 top-0 h-0.5 bg-primary" aria-hidden="true" />}
            <header className="flex items-center gap-2 border-b bg-muted/40 px-4 py-2">
                {email.agent ? (
                    <AgentMark name={email.agent.name} url={email.agent.avatar_url} className="size-5 rounded text-[8px]" />
                ) : (
                    <Mail className="size-4 shrink-0 text-muted-foreground" />
                )}
                <span className="min-w-0 flex-1 truncate text-xs font-medium text-foreground/85">{title}</span>
                {sending ? (
                    <span className="inline-flex shrink-0 items-center gap-1 text-[11px] text-primary" role="status">
                        <Send className="email-plane size-3" />A enviar…
                    </span>
                ) : sent ? (
                    <span
                        className={cn('inline-flex shrink-0 items-center gap-1 text-[11px] text-status-success', justSent && 'email-sent')}
                        role="status"
                    >
                        <Check className="size-3" />
                        Enviado{email.sent_at && ` às ${time(email.sent_at)}`}
                    </span>
                ) : sendError ? (
                    <span className="shrink-0 text-[11px] text-status-danger">Não foi enviado</span>
                ) : draft ? (
                    <span className="shrink-0 text-[11px] text-muted-foreground">Não enviado</span>
                ) : null}
            </header>

            {editing ? (
                <EditDraft email={email} onDone={() => setEditing(false)} />
            ) : (
                <>
                    <dl className="divide-y px-4 text-[13px]">
                        <Field label="Para">
                            {email.to.length > 0 ? (
                                email.to.join(', ')
                            ) : (
                                <span className="inline-flex flex-wrap items-center gap-1.5">
                                    {email.pending_recipient && <span>{email.pending_recipient}</span>}
                                    <span className="inline-flex items-center gap-1 rounded-full bg-status-warning/15 px-2 py-0.5 text-[11px] font-medium text-warning-strong">
                                        <AlertTriangle className="size-3" />
                                        Endereço em falta
                                    </span>
                                </span>
                            )}
                        </Field>
                        {email.cc.length > 0 && <Field label="Cc">{email.cc.join(', ')}</Field>}
                        {email.from && <Field label="De">{email.from}</Field>}
                        <Field label="Assunto">
                            <span className="font-medium">{email.subject || '(sem assunto)'}</span>
                        </Field>
                    </dl>
                    <div className="border-t px-4 py-3 text-sm leading-relaxed break-words whitespace-pre-wrap">{email.body}</div>
                    {email.attachments.length > 0 && (
                        <div className="flex flex-wrap gap-1.5 px-4 pb-3">
                            {email.attachments.map((file) => (
                                <span
                                    key={file.id}
                                    className="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs text-muted-foreground"
                                >
                                    <Paperclip className="size-3" />
                                    {file.name}
                                </span>
                            ))}
                        </div>
                    )}
                    <footer className="flex flex-wrap items-center gap-1.5 border-t bg-muted/20 px-3 py-2">
                        {draft && email.can_edit && (
                            <>
                                <ConfirmDialog
                                    title="Enviar este email?"
                                    description={`Para ${email.to.join(', ')}${email.cc.length > 0 ? `, com cópia a ${email.cc.join(', ')}` : ''}: «${email.subject || '(sem assunto)'}». Sai${email.from ? ` de ${email.from}` : ''} e não se pode desfazer.`}
                                    confirmLabel="Enviar"
                                    onConfirm={send}
                                    trigger={
                                        <Button
                                            size="sm"
                                            disabled={sending || email.to.length === 0}
                                            title={email.to.length === 0 ? 'Falta o endereço: use Editar.' : undefined}
                                        >
                                            <Send className={cn(sending && 'email-plane')} />
                                            {sending ? 'A enviar…' : sendError ? 'Tentar de novo' : 'Enviar'}
                                        </Button>
                                    }
                                />
                                {email.proposal && (
                                    <Button size="sm" variant="outline" disabled={keeping} onClick={keep}>
                                        <Save />
                                        Guardar em rascunho
                                    </Button>
                                )}
                                <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                                    <PenLine />
                                    Editar
                                </Button>
                                {email.proposal && (
                                    <ConfirmDialog
                                        title="Descartar a proposta?"
                                        description="O rascunho do agente deixa de aparecer aqui e no email."
                                        confirmLabel="Descartar"
                                        destructive
                                        onConfirm={discard}
                                        trigger={
                                            <Button size="sm" variant="ghost">
                                                <X />
                                                Descartar
                                            </Button>
                                        }
                                    />
                                )}
                            </>
                        )}
                        {!draft && email.can_open && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={`/inbox/${email.id}`}>
                                    <Mail />
                                    Abrir no Email
                                </Link>
                            </Button>
                        )}
                        <Button size="sm" variant="ghost" onClick={copy}>
                            {copied ? <Check /> : <Copy />}
                            {copied ? 'Copiado' : 'Copiar'}
                        </Button>
                        {draft && !email.can_edit && (
                            <span className="ml-auto text-[11px] text-muted-foreground">
                                {email.from ? `Rascunho na caixa ${email.from}; só quem a gere o envia.` : 'Só quem gere a caixa o envia.'}
                            </span>
                        )}
                        {draft && email.can_edit && sendError && <span className="ml-auto text-[11px] text-status-danger">{sendError}</span>}
                        {draft && email.can_edit && !sendError && (
                            <span className="ml-auto text-[11px] text-muted-foreground">
                                {email.proposal ? 'Ainda não está nos Rascunhos.' : 'Está nos Rascunhos do Email.'}
                            </span>
                        )}
                    </footer>
                </>
            )}
        </article>
    );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex gap-3 py-1.5">
            <dt className="w-14 shrink-0 text-muted-foreground">{label}</dt>
            <dd className="min-w-0 flex-1 break-words">{children}</dd>
        </div>
    );
}

/** Changes the draft itself, the one in Email; it then reads as the person's («Rascunho»). */
function EditDraft({ email, onDone }: { email: ProposedEmail; onDone: () => void }) {
    const [to, setTo] = useState(email.to.join(', '));
    const [cc, setCc] = useState(email.cc.join(', '));
    const [subject, setSubject] = useState(email.subject);
    const [body, setBody] = useState(email.body);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const save = () => {
        setSaving(true);
        router.put(
            `/inbox/drafts/${email.id}`,
            { mailbox_id: email.mailbox_id, to: list(to), cc: list(cc), subject, body, keep: email.attachments.map((file) => file.id) },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onDone(),
                onError: (failed) => setErrors(failed),
                onFinish: () => setSaving(false),
            },
        );
    };

    const error = Object.entries(errors).find(([key]) => key.startsWith('to'))?.[1] ?? Object.values(errors)[0];

    return (
        <div className="flex flex-col gap-2 px-4 py-3">
            <EditRow label="Para">
                <Input
                    value={to}
                    onChange={(e) => setTo(e.target.value)}
                    placeholder={email.pending_recipient ? `Endereço de ${email.pending_recipient}` : 'nome@empresa.co.mz'}
                    className="h-8"
                />
            </EditRow>
            <EditRow label="Cc">
                <Input value={cc} onChange={(e) => setCc(e.target.value)} className="h-8" />
            </EditRow>
            <EditRow label="Assunto">
                <Input value={subject} onChange={(e) => setSubject(e.target.value)} className="h-8" />
            </EditRow>
            <Textarea
                value={body}
                onChange={(e) => setBody(e.target.value)}
                rows={Math.min(18, Math.max(6, body.split('\n').length + 1))}
                className="text-sm"
            />
            {error && <p className={cn('text-xs text-status-danger')}>{error}</p>}
            <div className="flex items-center gap-1.5">
                <Button size="sm" onClick={save} disabled={saving}>
                    {saving ? 'A guardar…' : 'Guardar rascunho'}
                </Button>
                <Button size="sm" variant="ghost" onClick={onDone} disabled={saving}>
                    Cancelar
                </Button>
            </div>
        </div>
    );
}

function EditRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="flex items-center gap-3 text-[13px]">
            <span className="w-14 shrink-0 text-muted-foreground">{label}</span>
            <span className="min-w-0 flex-1">{children}</span>
        </label>
    );
}
