import { Lock } from 'lucide-react';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode, useEffect, useState } from 'react';

import { StatusBadge } from '@/Components/Status';
import { cn } from '@/lib/utils';

/*
 * Building blocks of the long definition forms (agents, skills, connectors):
 * a sticky section nav, sections without boxes, source pills.
 */

export const str = (value: number | string | null | undefined) => (value === null || value === undefined ? '' : String(value));

export interface SectionLink {
    id: string;
    label: string;
    icon: LucideIcon;
    count?: number;
    /** A field of the section has an error (a red dot). */
    error?: boolean;
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

export function SectionNav({
    sections,
    active,
    note,
    onSelect,
}: {
    sections: SectionLink[];
    active: string;
    note?: string;
    /** As tabs: one section shown at a time, the link only says which. */
    onSelect?: (id: string) => void;
}) {
    const link = (section: SectionLink, wide: boolean) => (
        <a
            key={section.id}
            href={`#${section.id}`}
            aria-current={active === section.id ? 'page' : undefined}
            onClick={(event) => {
                if (onSelect) {
                    event.preventDefault();
                    onSelect(section.id);
                    window.history.replaceState(window.history.state, '', `#${section.id}`);
                    window.scrollTo({ top: 0 });
                }
            }}
            className={cn(
                'flex h-8 shrink-0 items-center gap-2 rounded-lg px-2.5 text-sm whitespace-nowrap text-muted-foreground transition-colors hover:bg-accent/60 hover:text-foreground',
                active === section.id && 'bg-accent font-medium text-foreground',
            )}
        >
            <section.icon className="size-4 shrink-0" />
            <span className={cn(wide && 'truncate')}>{section.label}</span>
            {section.error && <span className="size-1.5 shrink-0 rounded-full bg-destructive" aria-label="Com erros" />}
            {section.count !== undefined && <span className={cn('font-mono text-[11px] tabular-nums', wide && 'ml-auto')}>{section.count}</span>}
        </a>
    );

    return (
        <>
            {/* On a phone or tablet, a row of tabs above the form. */}
            {onSelect && (
                <nav className="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 lg:hidden">
                    {sections.filter((s) => s.id !== 'assistente').map((s) => link(s, false))}
                </nav>
            )}
            <nav className="hidden lg:block">
                <div className="sticky top-20 flex flex-col gap-0.5">
                    <p className="mb-2 px-2.5 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">Secções</p>
                    {sections.filter((s) => !onSelect || s.id !== 'assistente').map((s) => link(s, true))}
                    {note && <p className="mt-3 px-2.5 text-xs text-muted-foreground">{note}</p>}
                </div>
            </nav>
        </>
    );
}

/**
 * A section of the builder, without a box (docs/UI.md, no borders on opened
 * content): on a wide screen the title and what it is for sit on the left and
 * the fields on the right, like a settings page; sections are apart by space
 * and a hairline.
 */
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
        <section
            id={id}
            className="grid scroll-mt-20 gap-x-10 gap-y-4 border-t border-border/60 pt-8 first:border-t-0 first:pt-0 2xl:grid-cols-[13rem_minmax(0,1fr)]"
        >
            <header className="flex min-w-0 flex-col gap-3">
                <div className="space-y-1">
                    <h2 className="text-sm font-semibold">{title}</h2>
                    {description && <p className="text-sm leading-relaxed text-muted-foreground">{description}</p>}
                </div>
                {action && <div className="flex items-center gap-2">{action}</div>}
            </header>
            <div className="min-w-0">
                {children}
                {footer && <div className="mt-4 flex items-center justify-end gap-2">{footer}</div>}
            </div>
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
