import { Head, Link, useForm } from '@inertiajs/react';
import { Lock, Loader2, MessageSquare, Pencil, Send } from 'lucide-react';
import type { FormEvent } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { InputError } from '@/Components/InputError';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';

/**
 * A conversation with an agent before its first message. Nothing exists yet:
 * the first message creates the conversation (one per person and agent).
 */
export default function AgentChat({ agent }: { agent: { id: number; name: string; title: string | null; avatar_url: string | null } }) {
    const form = useForm({ message: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
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

                <form onSubmit={submit} className="flex flex-col gap-2 rounded-xl border bg-card p-3">
                    <Textarea
                        value={form.data.message}
                        onChange={(e) => form.setData('message', e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey && form.data.message.trim() !== '') {
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
                    <InputError message={form.errors.message} />
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-xs text-muted-foreground">Enter envia · Shift+Enter muda de linha</span>
                        <Button type="submit" size="sm" disabled={form.processing || form.data.message.trim() === ''}>
                            {form.processing ? <Loader2 className="animate-spin" /> : <Send />}
                            Enviar
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
