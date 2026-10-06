import { Head, Link, useForm } from '@inertiajs/react';
import { Lock, Loader2, MessageSquare, Pencil, Send } from 'lucide-react';
import type { FormEvent } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { AttachButton, AttachmentTray, DropOverlay, RecordControl, useFileDrop } from '@/Components/attachments/Composer';
import { InputError } from '@/Components/InputError';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { useAttachments } from '@/hooks/useAttachments';
import AppLayout from '@/Layouts/AppLayout';
import type { AttachmentLimits } from '@/lib/attachments';

/**
 * A conversation with an agent before its first message. Nothing exists yet:
 * the first message creates the conversation (one per person and agent).
 */
export default function AgentChat({
    agent,
    attachment_limits,
}: {
    agent: { id: number; name: string; title: string | null; avatar_url: string | null };
    attachment_limits: AttachmentLimits;
}) {
    const form = useForm({ message: '', attachment_ids: [] as number[] });
    const files = useAttachments(attachment_limits, { agent_id: agent.id });
    const drop = useFileDrop(files.add);
    const canSend = !form.processing && !files.uploading && !files.failed && (form.data.message.trim() !== '' || files.ids.length > 0);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (!canSend) {
            return;
        }
        form.transform((data) => ({ ...data, attachment_ids: files.ids }));
        form.post(`/agents/${agent.id}/chat`);
    };

    return (
        <AppLayout breadcrumbs={[{ label: 'Conversas', href: '/tasks?view=chats' }, { label: agent.name }]}>
            <Head title={`Conversa com ${agent.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6">
                <header className="flex items-center gap-3">
                    <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-10 rounded-xl text-sm" />
                    <div className="min-w-0 flex-1">
                        <h1 className="truncate text-xl font-semibold tracking-tight">{agent.name}</h1>
                        <p className="truncate text-sm text-muted-foreground">
                            {agent.title ?? 'Assistente'} · uma só conversa contínua, com todo o histórico
                        </p>
                        <p className="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-muted px-2 py-1 text-xs text-foreground/80">
                            <Lock className="size-3.5 shrink-0 text-muted-foreground" />
                            As conversas podem ser consultadas pela direcção.
                        </p>
                    </div>
                    <Button variant="ghost" size="sm" asChild>
                        <Link href={`/agents/${agent.id}`}>
                            <Pencil className="sm:hidden" />
                            <span className="hidden sm:inline">Ver agente</span>
                        </Link>
                    </Button>
                </header>

                <div className="flex flex-col items-center gap-2 rounded-xl border border-dashed px-6 py-12 text-center">
                    <MessageSquare className="size-5 text-muted-foreground" />
                    <p className="text-sm font-medium">Ainda não falou com {agent.name}</p>
                    <p className="max-w-md text-sm text-muted-foreground">
                        Escreva abaixo o que precisa. Pode fazer perguntas ou pedir trabalho; o que for trabalho passa a uma tarefa.
                    </p>
                </div>

                <form onSubmit={submit} {...drop.dropProps} className="relative flex flex-col gap-2 rounded-xl border bg-card p-3">
                    <DropOverlay show={drop.dragging} />
                    <div className="-mx-3 -mt-3">
                        <AttachmentTray items={files.items} onRemove={files.remove} notice={files.notice} onDismissNotice={files.dismissNotice} />
                    </div>
                    <Textarea
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
                        placeholder={`Escreva a ${agent.name}…`}
                        className="resize-none border-0 shadow-none focus-visible:ring-0"
                        aria-label={`Mensagem para ${agent.name}`}
                    />
                    <InputError message={form.errors.message ?? form.errors.attachment_ids} />
                    <div className="flex flex-wrap items-center gap-2">
                        <AttachButton extensions={attachment_limits.extensions} onFiles={files.add} />
                        {attachment_limits.types.includes('audio') && (
                            <RecordControl maxSeconds={attachment_limits.audio_max_seconds} onRecorded={(file) => files.add([file], 'recording')} />
                        )}
                        <span className="hidden flex-1 text-xs text-muted-foreground sm:block">Enter envia · Shift+Enter muda de linha</span>
                        <Button type="submit" size="sm" className="ml-auto" disabled={!canSend}>
                            {form.processing ? <Loader2 className="animate-spin" /> : <Send />}
                            Enviar
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
