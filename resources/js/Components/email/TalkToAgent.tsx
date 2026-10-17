import { router, usePage } from '@inertiajs/react';
import { Bot, Loader2, Send } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { AgentMark } from '@/Components/AgentAvatar';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/*
 * Taking the open email to an agent (docs/DECISOES.md, "Falar com um agente
 * sobre um email"): pick the agent, say what you want, and the email goes
 * into your conversation with it, which opens.
 */
export function TalkToAgent({ emailId, subject, className }: { emailId: number; subject: string; className?: string }) {
    const agents = (usePage<SharedProps>().props.sidebar_agents ?? []).filter((agent) => agent.can_chat && agent.status === 'active');
    const [open, setOpen] = useState(false);
    const [agentId, setAgentId] = useState<number | null>(null);
    const [message, setMessage] = useState('');
    const [sending, setSending] = useState(false);

    if (agents.length === 0) {
        return null;
    }

    const send = (event: FormEvent) => {
        event.preventDefault();

        if (agentId === null) {
            return;
        }

        router.post(
            `/inbox/${emailId}/agent`,
            { agent_id: agentId, message },
            { onStart: () => setSending(true), onFinish: () => setSending(false), onSuccess: () => setOpen(false) },
        );
    };

    return (
        <>
            <Button variant="ghost" size="sm" className={className} onClick={() => setOpen(true)} title="Levar este email à conversa com um agente">
                <Bot />
                Falar com agente
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-lg">
                    <form onSubmit={send} className="flex min-w-0 flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle className="pr-6">Falar com um agente sobre este email</DialogTitle>
                            <DialogDescription className="line-clamp-3 break-words" title={subject}>
                                «{subject}» vai para a sua conversa com o agente, que o pode ler.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="-mx-1 flex max-h-56 flex-col gap-1 overflow-y-auto px-1 py-1" role="radiogroup" aria-label="Agente">
                            {agents.map((agent) => (
                                <button
                                    key={agent.id}
                                    type="button"
                                    role="radio"
                                    aria-checked={agentId === agent.id}
                                    onClick={() => setAgentId(agent.id)}
                                    className={cn(
                                        'flex items-center gap-3 rounded-lg border px-3 py-2 text-left text-sm hover:bg-accent',
                                        agentId === agent.id ? 'border-primary bg-primary/5' : 'border-transparent',
                                    )}
                                >
                                    <AgentMark name={agent.name} url={agent.avatar_url} className="size-7" />
                                    <span className="min-w-0 truncate font-medium">{agent.name}</span>
                                </button>
                            ))}
                        </div>

                        <Textarea
                            value={message}
                            onChange={(event) => setMessage(event.target.value)}
                            placeholder="O que quer? Ex.: prepara uma proposta para este cliente, ou o que achas deste pedido?"
                            rows={3}
                            className="field-sizing-fixed resize-y"
                            maxLength={10000}
                        />

                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={agentId === null || sending}>
                                {sending ? <Loader2 className="animate-spin" /> : <Send />}
                                Enviar ao agente
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
