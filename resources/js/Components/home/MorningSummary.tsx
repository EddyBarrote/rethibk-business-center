import { Link, router } from '@inertiajs/react';
import { Play, X } from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Button } from '@/Components/ui/button';
import { cn } from '@/lib/utils';

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

    const close = () => router.post('/morning-summary/close', {}, { preserveScroll: true, only: ['morning'] });

    return (
        <section className="flex w-full flex-col gap-5 text-left" aria-label="Resumo da manhã">
            <header className="flex items-center gap-2.5">
                {morning.from && <AgentAvatar name={morning.from.name} url={morning.from.avatar_url} className="size-7 rounded-lg text-[9px]" />}
                <div className="min-w-0 flex-1 leading-tight">
                    <p className="text-sm font-medium">Resumo de hoje</p>
                    <p className="text-xs text-muted-foreground">
                        {empty ? 'Nada à sua espera.' : morning.from ? `Do ${morning.from.name}` : 'O seu dia'}
                    </p>
                </div>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    className="shrink-0 text-muted-foreground"
                    aria-label="Fechar o resumo"
                    title="Fechar: volta amanhã"
                    onClick={close}
                >
                    <X />
                </Button>
            </header>

            {morning.needs.length > 0 && (
                <Section title="Precisa de si" count={morning.needs.length}>
                    {morning.needs.map((line) => (
                        <Card key={`${line.kind}-${line.href}`} href={line.href} title={line.title} detail={line.why} tone="attention">
                            {line.start && (
                                <Button
                                    type="button"
                                    size="xs"
                                    variant="outline"
                                    className="relative z-10 bg-background"
                                    disabled={starting !== null}
                                    title={`Entrega este email a ${line.start.agent}`}
                                    onClick={() => start(line)}
                                >
                                    <Play />
                                    Começar
                                </Button>
                            )}
                        </Card>
                    ))}
                </Section>
            )}

            {morning.can_wait.length > 0 && (
                <Section title="Pode esperar" count={morning.can_wait.length}>
                    {morning.can_wait.map((line) => (
                        <Card key={line.href} href={line.href} title={line.title} detail={line.why} />
                    ))}
                </Section>
            )}

            {morning.agenda.length > 0 && (
                <Section title="Hoje" count={morning.agenda.length}>
                    {morning.agenda.map((item, index) => (
                        <Card key={`${item.href}-${index}`} href={item.href} title={item.title} lead={item.time} />
                    ))}
                </Section>
            )}

            {morning.unverified.length > 0 && (
                <Section title="Não consegui verificar" count={morning.unverified.length}>
                    {morning.unverified.map((line) => (
                        <Card key={line.what} href={line.href} title={line.what} detail={line.why} tone="warning" />
                    ))}
                </Section>
            )}
        </section>
    );
}

function Section({ title, count, children }: { title: string; count: number; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-2">
            <h2 className="flex items-baseline gap-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                {title}
                <span className="tabular-nums">{count}</span>
            </h2>
            <ul className="flex flex-col gap-1.5">{children}</ul>
        </div>
    );
}

/**
 * One line of the day as a quiet card: the title opens it (the whole card is
 * the link), the reason in small print, an action beside it when there is one.
 */
export function Card({
    href,
    title,
    detail,
    lead,
    tone = 'plain',
    children,
}: {
    href: string;
    title: string;
    detail?: string | null;
    lead?: string | null;
    tone?: 'plain' | 'attention' | 'warning';
    children?: ReactNode;
}) {
    return (
        <li
            className={cn(
                'relative flex items-start gap-2.5 rounded-lg px-3 py-2.5 transition-colors',
                tone === 'warning' ? 'bg-status-warning/10 hover:bg-status-warning/15' : 'bg-muted/50 hover:bg-muted',
            )}
        >
            {tone === 'attention' && <span className="mt-1.5 size-1.5 shrink-0 rounded-full bg-status-warning" aria-hidden="true" />}
            {lead && <span className="mt-px w-12 shrink-0 text-xs text-muted-foreground tabular-nums">{lead}</span>}
            <div className="min-w-0 flex-1">
                <Link href={href} className="line-clamp-2 text-sm leading-snug font-medium after:absolute after:inset-0 after:rounded-lg">
                    {title}
                </Link>
                {detail && <p className="mt-0.5 line-clamp-2 text-xs text-muted-foreground">{detail}</p>}
                {children && <div className="mt-2 flex justify-end">{children}</div>}
            </div>
        </li>
    );
}
