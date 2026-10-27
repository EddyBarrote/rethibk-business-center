import { Link, router } from '@inertiajs/react';
import { ArrowRight, Play, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Button } from '@/Components/ui/button';

interface Line {
    kind?: string;
    title: string;
    why: string;
    href: string;
    start?: { email_id: number; agent_id: number; agent: string } | null;
}

export interface Morning {
    date: string;
    from: { name: string; avatar_url: string | null } | null;
    greeting: string;
    needs: Line[];
    can_wait: Line[];
    agenda: { time: string | null; title: string; href: string }[];
    unverified: { what: string; why: string; href: string }[];
}

/**
 * The morning summary (docs/DECISOES.md, "Resumo da manhã"): written by the
 * Chief of Staff as a message, once a day until closed. Each line goes where
 * it is decided; an email has «Começar», which hands it to its agent.
 */
export function MorningSummary({ morning }: { morning: Morning }) {
    const [starting, setStarting] = useState<number | null>(null);
    const empty = morning.needs.length === 0 && morning.can_wait.length === 0 && morning.agenda.length === 0;

    const start = (line: Line) => {
        if (!line.start) {
            return;
        }
        setStarting(line.start.email_id);
        router.post(
            `/inbox/${line.start.email_id}/agent`,
            { agent_id: line.start.agent_id, message: 'Trata deste email: vê o que pede e prepara o que for preciso. Nada sai sem eu ver.' },
            { onFinish: () => setStarting(null) },
        );
    };

    return (
        <section className="flex w-full gap-3 text-left" aria-label="Resumo da manhã">
            {morning.from && <AgentAvatar name={morning.from.name} url={morning.from.avatar_url} className="mt-0.5 size-8 rounded-lg text-[10px]" />}
            <div className="min-w-0 flex-1 space-y-4 text-sm">
                <div className="flex items-start justify-between gap-2">
                    <p>
                        <span className="font-medium">{morning.greeting}</span>{' '}
                        {empty ? 'Hoje não há nada à sua espera.' : 'Isto é o que tem para hoje.'}
                    </p>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        className="-mt-1 shrink-0 text-muted-foreground"
                        aria-label="Fechar o resumo"
                        title="Fechar: volta amanhã"
                        onClick={() => router.post('/morning-summary/close', {}, { preserveScroll: true, only: ['morning'] })}
                    >
                        <X />
                    </Button>
                </div>

                {morning.needs.length > 0 && (
                    <Section title="Precisa de si">
                        {morning.needs.map((line) => (
                            <li key={`${line.kind}-${line.href}`} className="flex items-start gap-2">
                                <span className="min-w-0 flex-1">
                                    <Link href={line.href} className="font-medium underline-offset-4 hover:underline">
                                        {line.title}
                                    </Link>
                                    <span className="block text-muted-foreground">{line.why}</span>
                                </span>
                                {line.start && (
                                    <Button
                                        type="button"
                                        size="xs"
                                        variant="outline"
                                        className="shrink-0"
                                        disabled={starting !== null}
                                        title={`Entrega este email a ${line.start.agent}`}
                                        onClick={() => start(line)}
                                    >
                                        <Play />
                                        Começar
                                    </Button>
                                )}
                            </li>
                        ))}
                    </Section>
                )}

                {morning.can_wait.length > 0 && (
                    <Section title="Pode esperar">
                        {morning.can_wait.map((line) => (
                            <li key={line.href}>
                                <Link href={line.href} className="underline-offset-4 hover:underline">
                                    {line.title}
                                </Link>
                                <span className="text-muted-foreground"> · {line.why}</span>
                            </li>
                        ))}
                    </Section>
                )}

                {morning.agenda.length > 0 && (
                    <Section title="Hoje">
                        {morning.agenda.map((item, index) => (
                            <li key={`${item.href}-${index}`} className="flex gap-3">
                                <span className="w-16 shrink-0 text-muted-foreground tabular-nums">{item.time}</span>
                                <Link href={item.href} className="min-w-0 truncate underline-offset-4 hover:underline">
                                    {item.title}
                                </Link>
                            </li>
                        ))}
                    </Section>
                )}

                {morning.unverified.length > 0 && (
                    <Section title="O que não consegui verificar">
                        {morning.unverified.map((line) => (
                            <li key={line.what}>
                                {line.what} {line.why}{' '}
                                <Link href={line.href} className="inline-flex items-center gap-0.5 text-primary hover:underline">
                                    Ver
                                    <ArrowRight className="size-3" />
                                </Link>
                            </li>
                        ))}
                    </Section>
                )}
            </div>
        </section>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="space-y-1.5">
            <h2 className="text-xs font-medium text-muted-foreground">{title}</h2>
            <ul className="space-y-2">{children}</ul>
        </div>
    );
}
