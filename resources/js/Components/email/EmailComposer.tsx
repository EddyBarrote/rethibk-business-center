import { router, usePage } from '@inertiajs/react';
import { Loader2, Save, Send, Trash2, X } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

import { AgentMark } from '@/Components/AgentAvatar';
import { AttachButton, AttachmentTray, DropOverlay, useFileDrop } from '@/Components/attachments/Composer';
import { ConfirmDialog } from '@/Components/Dialogs';
import { FileIcon } from '@/Components/FileIcon';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import { useAttachments } from '@/hooks/useAttachments';
import { type AttachmentLimits, fileSize } from '@/lib/attachments';
import { addressList, type Composition, type EmailFile, type EmailMailbox } from '@/lib/email';
import { cn } from '@/lib/utils';

/*
 * Writing an email: from one of the person's mailboxes, with Cc and Bcc,
 * files (uploaded as they are added, like in the conversations), saved as a
 * draft in the server's Rascunhos every half minute and on request.
 */

function Recipients({
    id,
    label,
    value,
    onChange,
    error,
    autoFocus,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (v: string) => void;
    error?: string;
    autoFocus?: boolean;
}) {
    return (
        <div className="flex flex-col">
            <label htmlFor={id} className="flex items-center gap-2 border-b px-4">
                <span className="w-12 shrink-0 text-xs text-muted-foreground">{label}</span>
                <input
                    id={id}
                    value={value}
                    autoFocus={autoFocus}
                    onChange={(e) => onChange(e.target.value)}
                    placeholder="nome@empresa.co.mz, outro@empresa.co.mz"
                    className="h-10 min-w-0 flex-1 bg-transparent text-sm outline-none placeholder:text-muted-foreground/50"
                    autoComplete="email"
                />
            </label>
            {error && <p className="px-4 pt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}

export function EmailComposer({
    initial,
    mailboxes,
    limits,
    returnTo,
    onClose,
}: {
    initial: Composition;
    mailboxes: EmailMailbox[];
    limits: AttachmentLimits;
    returnTo: { folder: number | null; message: number | null };
    onClose: () => void;
}) {
    const page = usePage<{ draft_saved: { id: number; attachments: EmailFile[] } | null; errors: Record<string, string> }>();
    const [form, setForm] = useState(initial);
    const [showCopies, setShowCopies] = useState(initial.cc !== '' || initial.bcc !== '');
    const [processing, setProcessing] = useState<'save' | 'send' | null>(null);
    const [savedAt, setSavedAt] = useState<Date | null>(null);
    const dirty = useRef(false);
    const draftId = useRef<number | null>(initial.draftId);
    const files = useAttachments(limits, { mailbox_id: form.mailboxId });
    const drop = useFileDrop(files.add);
    const errors = page.props.errors ?? {};
    const writable = mailboxes.filter((m) => m.can_write || m.id === initial.mailboxId);

    const set = <K extends keyof Composition>(key: K, value: Composition[K]) => {
        dirty.current = true;
        setForm((current) => ({ ...current, [key]: value }));
    };

    const payload = (extra: Record<string, unknown> = {}) => ({
        mailbox_id: form.mailboxId,
        reply_to_id: form.replyToId,
        to: addressList(form.to),
        cc: addressList(form.cc),
        bcc: addressList(form.bcc),
        subject: form.subject,
        body: form.body,
        uploads: files.ids,
        keep: form.keep.map((file) => file.id),
        ...extra,
    });

    const save = (autosave = false) => {
        if (processing || files.uploading) {
            return;
        }

        setProcessing(autosave ? null : 'save');
        const id = draftId.current;
        const options = {
            preserveScroll: true,
            preserveState: true,
            only: ['draft_saved', 'flash', 'errors'],
            onSuccess: (visit: { props: Record<string, unknown> }) => {
                const saved = visit.props.draft_saved as { id: number; attachments: EmailFile[] } | null;
                dirty.current = false;
                setSavedAt(new Date());

                if (saved) {
                    draftId.current = saved.id;
                    // The uploads now belong to the draft, as its own files.
                    setForm((current) => ({ ...current, keep: saved.attachments }));
                    files.clear();
                }
            },
            onFinish: () => setProcessing(null),
        };

        if (id) {
            router.put(`/inbox/drafts/${id}`, payload({ autosave }), options);
        } else {
            router.post('/inbox/drafts', payload({ autosave }), options);
        }
    };

    // A send that failed leaves the email saved as a draft: keep editing that one.
    useEffect(() => {
        const saved = page.props.draft_saved;

        if (saved && (draftId.current === null || draftId.current === saved.id)) {
            draftId.current = saved.id;
        }
    }, [page.props.draft_saved]);

    // Saved every half minute while the person writes.
    useEffect(() => {
        const timer = window.setInterval(() => dirty.current && save(true), 30_000);

        return () => window.clearInterval(timer);
    });

    const send = (event: FormEvent) => {
        event.preventDefault();
        const id = draftId.current;

        setProcessing('send');
        router.post(id ? `/inbox/${id}/send` : '/inbox/send', payload({ return_folder: returnTo.folder, return_message: returnTo.message }), {
            preserveScroll: true,
            onSuccess: (visit) => {
                const props = visit.props as { flash?: { error?: string | null }; draft_saved?: { id: number; attachments: EmailFile[] } | null };
                files.clear();

                if (!props.flash?.error) {
                    onClose();
                } else if (props.draft_saved) {
                    // Not sent, but saved as a draft with its files.
                    draftId.current = props.draft_saved.id;
                    setForm((current) => ({ ...current, keep: props.draft_saved?.attachments ?? current.keep }));
                }
            },
            onFinish: () => setProcessing(null),
        });
    };

    const discard = () => {
        const id = draftId.current;
        files.items.forEach((item) => files.remove(item.key));

        if (id) {
            router.delete(`/inbox/${id}`, { preserveScroll: true, onSuccess: onClose });
        } else {
            onClose();
        }
    };

    const close = () => {
        if (dirty.current && (form.body.trim() !== '' || form.to.trim() !== '' || form.subject.trim() !== '')) {
            save(true);
        }

        onClose();
    };

    return (
        <form onSubmit={send} className="relative flex h-full min-h-0 flex-col" {...drop.dropProps} onPaste={drop.onPaste}>
            <DropOverlay show={drop.dragging} />

            <header className="flex items-center gap-2 border-b px-4 py-2.5">
                {form.agent && <AgentMark name={form.agent.name} url={form.agent.avatar_url} className="size-6" />}
                <p className="min-w-0 flex-1 truncate text-sm font-semibold">{form.title}</p>
                {savedAt && (
                    <span className="text-xs text-muted-foreground">
                        Guardado às {savedAt.toLocaleTimeString('pt-PT', { hour: '2-digit', minute: '2-digit' })}
                    </span>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    onClick={close}
                    aria-label="Fechar (fica nos rascunhos)"
                    title="Fechar (fica nos rascunhos)"
                >
                    <X />
                </Button>
            </header>

            <div className="flex items-center gap-2 border-b px-4">
                <span className="w-12 shrink-0 text-xs text-muted-foreground">De</span>
                <NativeSelect
                    aria-label="Caixa de onde sai"
                    className="h-10 min-w-0 flex-1 border-0 bg-transparent px-0 text-sm shadow-none focus-visible:ring-0"
                    value={form.mailboxId}
                    disabled={form.replyToId !== null || draftId.current !== null}
                    onChange={(e) => set('mailboxId', Number(e.target.value))}
                >
                    {writable.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.name} &lt;{m.address}&gt;
                        </option>
                    ))}
                </NativeSelect>
                {!showCopies && (
                    <button type="button" className="text-xs text-muted-foreground hover:text-foreground" onClick={() => setShowCopies(true)}>
                        Cc / Bcc
                    </button>
                )}
            </div>
            <Recipients
                id="compose-to"
                label="Para"
                value={form.to}
                onChange={(v) => set('to', v)}
                error={errors.to ?? errors['to.0']}
                autoFocus={form.to === ''}
            />
            {showCopies && (
                <>
                    <Recipients id="compose-cc" label="Cc" value={form.cc} onChange={(v) => set('cc', v)} error={errors['cc.0']} />
                    <Recipients id="compose-bcc" label="Bcc" value={form.bcc} onChange={(v) => set('bcc', v)} error={errors['bcc.0']} />
                </>
            )}
            <label htmlFor="compose-subject" className="flex items-center gap-2 border-b px-4">
                <span className="w-12 shrink-0 text-xs text-muted-foreground">Assunto</span>
                <Input
                    id="compose-subject"
                    value={form.subject}
                    onChange={(e) => set('subject', e.target.value)}
                    className="h-10 border-0 bg-transparent px-0 shadow-none focus-visible:ring-0"
                />
            </label>
            {errors.subject && <p className="px-4 pt-1 text-xs text-destructive">{errors.subject}</p>}

            <Textarea
                aria-label="Texto do email"
                value={form.body}
                onChange={(e) => set('body', e.target.value)}
                autoFocus={form.to !== ''}
                onFocus={(e) => form.replyToId !== null && form.body.startsWith('\n') && e.currentTarget.setSelectionRange(0, 0)}
                className="min-h-48 flex-1 resize-none rounded-none border-0 px-4 py-3 text-sm leading-relaxed shadow-none focus-visible:ring-0"
            />

            {form.keep.length > 0 && (
                <ul className="flex flex-wrap gap-2 px-3 pt-3" aria-label="Ficheiros do email">
                    {form.keep.map((file) => (
                        <li
                            key={file.id}
                            className="relative flex h-14 max-w-60 items-center gap-2 rounded-lg border bg-background pr-8 pl-1.5 text-xs"
                        >
                            <FileIcon extension={file.extension} className="size-11" />
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="truncate font-medium">{file.name}</span>
                                <span className="text-muted-foreground">{fileSize(file.size)}</span>
                            </span>
                            <button
                                type="button"
                                onClick={() =>
                                    set(
                                        'keep',
                                        form.keep.filter((k) => k.id !== file.id),
                                    )
                                }
                                className="absolute top-1 right-1 inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                                aria-label={`Retirar ${file.name}`}
                            >
                                <X className="size-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            <AttachmentTray items={files.items} onRemove={files.remove} notice={files.notice} onDismissNotice={files.dismissNotice} />

            <footer className="flex flex-wrap items-center gap-1 border-t px-3 py-2.5">
                <Button type="submit" disabled={processing !== null || files.uploading || files.failed}>
                    {processing === 'send' ? <Loader2 className="animate-spin" /> : <Send />}
                    Enviar
                </Button>
                <AttachButton extensions={limits.extensions} onFiles={files.add} disabled={processing !== null} />
                <Button type="button" variant="ghost" size="sm" onClick={() => save(false)} disabled={processing !== null || files.uploading}>
                    {processing === 'save' ? <Loader2 className="animate-spin" /> : <Save />}
                    Guardar rascunho
                </Button>
                <span className={cn('ml-auto text-xs text-muted-foreground', files.uploading ? '' : 'hidden')}>A carregar ficheiros…</span>
                <ConfirmDialog
                    title="Descartar este email?"
                    description="O rascunho sai da caixa, aqui e no servidor de email, e não é enviado."
                    confirmLabel="Descartar"
                    destructive
                    onConfirm={discard}
                    trigger={
                        <Button type="button" variant="ghost" size="icon" className="size-8" aria-label="Descartar" title="Descartar">
                            <Trash2 />
                        </Button>
                    }
                />
            </footer>
        </form>
    );
}
