import { BookOpen, Check, Database, FileText, Mail, ShieldCheck, Users, type LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

import { RethinkMark } from '@/Components/RethinkMark';
import { cn } from '@/lib/utils';

/*
 * The sign-in picture, as motion: the Chief of Staff in the middle, the places
 * the agents work around it, and small packets of work travelling between
 * them while a line below says what just happened. Drawn on a 600 × 700 grid;
 * the nodes are HTML on top of the SVG so they use the console's own pieces.
 */

const HUB = { x: 300, y: 300 };

type Node = { key: string; label: string; icon: LucideIcon; x: number; y: number };

const nodes: Node[] = [
    { key: 'email', label: 'Email', icon: Mail, x: 128, y: 120 },
    { key: 'erp', label: 'ERP', icon: Database, x: 478, y: 112 },
    { key: 'docs', label: 'Documentos', icon: FileText, x: 532, y: 318 },
    { key: 'approvals', label: 'Aprovações', icon: ShieldCheck, x: 446, y: 500 },
    { key: 'clients', label: 'Clientes', icon: Users, x: 150, y: 492 },
    { key: 'knowledge', label: 'Conhecimento', icon: BookOpen, x: 74, y: 300 },
];

/** What just happened, one line at a time, with the place it happened. */
const events: { node: string; text: string }[] = [
    { node: 'email', text: 'Agente de Triagem leu 12 emails novos' },
    { node: 'erp', text: 'Agente de Finanças registou a factura no ERP' },
    { node: 'docs', text: 'Gestor de Clientes preparou a proposta' },
    { node: 'approvals', text: 'Chief of Staff pediu a sua aprovação' },
    { node: 'clients', text: 'Proposta enviada ao cliente' },
    { node: 'knowledge', text: 'Agente de Procurement consultou os preços de 2025' },
];

/** A gentle curve from the hub to a node, bent to one side so the lines fan out. */
function curve(node: Node): string {
    const mx = (HUB.x + node.x) / 2;
    const my = (HUB.y + node.y) / 2;
    const dx = node.x - HUB.x;
    const dy = node.y - HUB.y;

    return `M${HUB.x} ${HUB.y} Q${mx - dy * 0.22} ${my + dx * 0.22} ${node.x} ${node.y}`;
}

function useReducedMotion(): boolean {
    const [reduced, setReduced] = useState(() => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    useEffect(() => {
        const query = window.matchMedia('(prefers-reduced-motion: reduce)');
        const update = () => setReduced(query.matches);
        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return reduced;
}

const at = (value: number, of: number) => `${(value / of) * 100}%`;

export function AgentsMotion({ className }: { className?: string }) {
    const reduced = useReducedMotion();
    const [current, setCurrent] = useState(0);

    useEffect(() => {
        if (reduced) {
            return;
        }
        const timer = window.setInterval(() => setCurrent((index) => (index + 1) % events.length), 2800);

        return () => window.clearInterval(timer);
    }, [reduced]);

    const event = events[current];

    return (
        <div className={cn('relative aspect-[6/7] select-none', className)} aria-hidden="true">
            <svg viewBox="0 0 600 700" className="absolute inset-0 size-full overflow-visible">
                {nodes.map((node) => (
                    <path
                        key={node.key}
                        d={curve(node)}
                        fill="none"
                        strokeWidth={node.key === event.node ? 1.75 : 1.25}
                        strokeDasharray="2 6"
                        strokeLinecap="round"
                        className={cn('transition-[stroke] duration-500', node.key === event.node ? 'stroke-primary/70' : 'stroke-foreground/35')}
                    />
                ))}

                {!reduced &&
                    nodes.map((node, index) => {
                        const duration = 2.6 + (index % 3) * 0.5;
                        const outward = index % 2 === 0;

                        return [0, duration / 2].map((delay) => (
                            <g key={`${node.key}-${delay}`} opacity="0" className="text-primary">
                                <circle r="9" fill="currentColor" opacity="0.18" />
                                <circle r="3.5" fill="currentColor" />
                                <animateMotion
                                    dur={`${duration}s`}
                                    begin={`${index * 0.35 + delay}s`}
                                    repeatCount="indefinite"
                                    path={curve(node)}
                                    keyPoints={outward ? '0;1' : '1;0'}
                                    keyTimes="0;1"
                                    calcMode="linear"
                                />
                                <animate
                                    attributeName="opacity"
                                    values="0;1;1;0"
                                    keyTimes="0;0.15;0.85;1"
                                    dur={`${duration}s`}
                                    begin={`${index * 0.35 + delay}s`}
                                    repeatCount="indefinite"
                                />
                            </g>
                        ));
                    })}
            </svg>

            <div className="absolute -translate-x-1/2 -translate-y-1/2" style={{ left: at(HUB.x, 600), top: at(HUB.y, 700) }}>
                <div className="relative flex flex-col items-center">
                    <span className="motion-ring absolute top-0 size-24 rounded-[28px] bg-primary/30" />
                    <span className="motion-ring absolute top-0 size-24 rounded-[28px] bg-primary/20 [animation-delay:1.6s]" />
                    <RethinkMark className="relative size-24 rounded-[28px] shadow-[0_18px_50px_-12px_color-mix(in_oklch,var(--primary)_70%,transparent)]" />
                    <div className="mt-3 rounded-2xl bg-background/80 px-3 py-1 text-center backdrop-blur-sm">
                        <p className="text-sm font-semibold">Chief of Staff</p>
                        <p className="text-xs text-muted-foreground">a coordenar a equipa</p>
                    </div>
                </div>
            </div>

            {nodes.map((node, index) => {
                const Icon = node.icon;
                const active = node.key === event.node;

                return (
                    <div
                        key={node.key}
                        className="absolute -translate-x-1/2 -translate-y-1/2"
                        style={{ left: at(node.x, 600), top: at(node.y, 700) }}
                    >
                        <div
                            className={cn(
                                'motion-float flex items-center gap-2.5 rounded-2xl border bg-card/85 py-2 pr-4 pl-2 text-sm font-medium whitespace-nowrap shadow-lg backdrop-blur-md transition-[box-shadow,border-color] duration-500',
                                active && 'border-primary/50 ring-4 ring-primary/15',
                            )}
                            style={{ animationDelay: `${index * -1.1}s` }}
                        >
                            <span
                                className={cn(
                                    'flex size-8 items-center justify-center rounded-xl transition-colors duration-500',
                                    active ? 'bg-primary text-primary-foreground' : 'bg-primary/12 text-primary',
                                )}
                            >
                                <Icon className="size-4" />
                            </span>
                            {node.label}
                        </div>
                    </div>
                );
            })}

            <div className="absolute inset-x-0 flex justify-center" style={{ top: at(632, 700) }}>
                <div
                    key={current}
                    className="motion-toast flex items-center gap-2.5 rounded-full border bg-card/90 py-2 pr-4 pl-2 text-sm shadow-lg backdrop-blur-md"
                >
                    <span className="flex size-6 items-center justify-center rounded-full bg-status-success text-white">
                        <Check className="size-3.5" strokeWidth={3} />
                    </span>
                    {event.text}
                </div>
            </div>
        </div>
    );
}
