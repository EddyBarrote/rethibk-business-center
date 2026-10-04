import { Lock } from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { StatusBadge } from '@/Components/Status';
import { cn } from '@/lib/utils';

/*
 * Building blocks of the long definition forms (agents, skills, connectors):
 * a sticky section nav, bordered sections, source pills.
 */

export const str = (value: number | string | null | undefined) => (value === null || value === undefined ? '' : String(value));

export interface SectionLink {
    id: string;
    label: string;
    icon: LucideIcon;
    count?: number;
}


/** The section whose heading was scrolled past last, for the sticky section nav. */
export function useActiveSection(ids: string[]) {
    const [active, setActive] = useState(ids[0]);
    const key = ids.join(',');

    useEffect(() => {
        let frame = 0;
        const update = () => {
            frame = 0;
            const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;
            let current = ids[0];

            for (const id of ids) {
                const element = document.getElementById(id);

                if (element && element.getBoundingClientRect().top <= 120) {
                    current = id;
                }
            }

            setActive(atBottom ? ids[ids.length - 1] : current);
        };
        const onScroll = () => {
            if (!frame) {
                frame = requestAnimationFrame(update);
            }
        };

        update();
        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            window.removeEventListener('scroll', onScroll);
            cancelAnimationFrame(frame);
        };
    }, [key]);

    return active;
}

export function SectionNav({ sections, active, note }: { sections: SectionLink[]; active: string; note?: string }) {
    return (
        <nav className="hidden lg:block">
            <div className="sticky top-20 flex flex-col gap-0.5">
                <p className="mb-2 px-2.5 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">Secções</p>
                {sections.map((section) => (
                    <a
                        key={section.id}
                        href={`#${section.id}`}
                        className={cn(
                            'flex h-8 items-center gap-2 rounded-lg px-2.5 text-sm text-muted-foreground transition-colors hover:bg-accent/60 hover:text-foreground',
                            active === section.id && 'bg-accent font-medium text-foreground',
                        )}
                    >
                        <section.icon className="size-4 shrink-0" />
                        <span className="truncate">{section.label}</span>
                        {section.count !== undefined && <span className="ml-auto font-mono text-[11px] tabular-nums">{section.count}</span>}
                    </a>
                ))}
                {note && <p className="mt-3 px-2.5 text-xs text-muted-foreground">{note}</p>}
            </div>
        </nav>
    );
}

/** A bordered block of the builder: heading strip, body, optional footer. */
export function FormSection({
    id,
    title,
    description,
    action,
    footer,
    children,
}: {
    id: string;
    title: string;
    description?: ReactNode;
    action?: ReactNode;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section id={id} className="scroll-mt-20 overflow-hidden rounded-xl border bg-card">
            <header className="flex flex-col gap-3 border-b px-5 py-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0 space-y-1">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {description && <p className="text-sm text-muted-foreground">{description}</p>}
                </div>
                {action && <div className="flex shrink-0 items-center gap-2">{action}</div>}
            </header>
            <div className="p-5">{children}</div>
            {footer && <div className="flex items-center justify-end gap-2 border-t bg-muted/30 px-5 py-3">{footer}</div>}
        </section>
    );
}

const sourceLabels: Record<string, string> = { local: 'plataforma', mcp: 'ERP', connector: 'conector' };

export function SourcePill({ source, scope }: { source: string; scope?: string }) {
    return (
        <span className="inline-flex h-5 items-center rounded-full border px-2 font-mono text-[11px] text-muted-foreground">
            {source === 'connector' && scope === 'global' ? 'conector global' : (sourceLabels[source] ?? source)}
        </span>
    );
}

export function ScopePill({ scope }: { scope: string }) {
    return (
        <StatusBadge tone={scope === 'tenant' ? 'running' : 'idle'} dot={false}>
            {scope === 'tenant' ? 'da empresa' : 'global'}
        </StatusBadge>
    );
}

export function CeilingPill() {
    return (
        <StatusBadge tone="danger" dot={false}>
            <Lock className="size-3" />
            tecto
        </StatusBadge>
    );
}


export function InputErrorList({ errors, prefix }: { errors: Record<string, string | undefined>; prefix: string }) {
    const messages = Object.entries(errors)
        .filter(([key]) => key === prefix || key.startsWith(`${prefix}.`))
        .map(([, message]) => message);

    return messages.length ? <p className="mb-3 text-sm text-destructive">{messages[0]}</p> : null;
}
