import { Link, router } from '@inertiajs/react';
import { EyeOff, Play } from 'lucide-react';
import { useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

export interface Initiative {
    id: number;
    agent: { id: number; name: string; avatar_url: string | null };
    title: string;
    why: string;
    plan: string;
    evidence: { ref: string; label: string; href: string }[];
    created_at: string;
}

/**
 * What the agents offer to do on their own (docs/DECISOES.md, "Iniciativas dos
 * agentes"), above the box to write in: what they saw, the evidence, and
 * «Começar» (opens the task, the agent starts) or «Esconder».
 */
export function Initiatives({ initiatives, showAgent = true, className }: { initiatives: Initiative[]; showAgent?: boolean; className?: string }) {
    const [busy, setBusy] = useState<number | null>(null);

    if (initiatives.length === 0) {
        return null;
    }

    const act = (initiative: Initiative, action: 'start' | 'hide') => {
        setBusy(initiative.id);
        router.post(`/initiatives/${initiative.id}/${action}`, {}, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <section className={cn('flex w-full flex-col gap-1.5 text-left', className)} aria-label="Os agentes podem tratar disto">
            {initiatives.map((initiative) => (
                <div key={initiative.id} className="flex gap-2.5 rounded-lg bg-muted/50 px-3 py-2.5 text-sm transition-colors hover:bg-muted">
                    {showAgent && (
                        <AgentAvatar name={initiative.agent.name} url={initiative.agent.avatar_url} className="mt-0.5 size-7 rounded-lg text-[9px]" />
                    )}
                    <div className="min-w-0 flex-1">
                        <p>
                            <span className="text-muted-foreground">{showAgent && `${initiative.agent.name} · `}Posso tratar disto: </span>
                            <span className="font-medium">{initiative.title}</span>
                        </p>
                        <p className="mt-0.5 line-clamp-3 text-xs text-muted-foreground">{initiative.why}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {initiative.evidence.map((item, index) => (
                                <span key={item.ref}>
                                    {index > 0 && ' · '}
                                    <Link href={item.href} className="underline-offset-4 hover:text-foreground hover:underline">
                                        {item.label}
                                    </Link>
                                </span>
                            ))}
                        </p>
                        <div className="mt-2 flex gap-1.5">
                            <Button size="xs" disabled={busy !== null} onClick={() => act(initiative, 'start')} title={initiative.plan}>
                                <Play />
                                Começar
                            </Button>
                            <Button
                                size="xs"
                                variant="ghost"
                                className="text-muted-foreground"
                                disabled={busy !== null}
                                onClick={() => act(initiative, 'hide')}
                            >
                                <EyeOff />
                                Esconder
                            </Button>
                        </div>
                    </div>
                </div>
            ))}
        </section>
    );
}
