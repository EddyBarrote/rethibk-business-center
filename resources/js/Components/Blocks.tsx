import { Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/*
 * Layout primitives for an operator console, adapted from Paperclip (MIT):
 * hierarchy through structure rather than decoration, dense rows over big cards.
 */

/** Small uppercase heading over a block of content, with an optional link or action on the right. */
export function Section({ title, action, children, className }: { title: string; action?: ReactNode; children: ReactNode; className?: string }) {
    return (
        <section className={cn('flex min-w-0 flex-col gap-3', className)}>
            <div className="flex min-h-6 items-center justify-between gap-3">
                <h2 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">{title}</h2>
                {action && <div className="flex items-center gap-2 text-sm">{action}</div>}
            </div>
            {children}
        </section>
    );
}

/** A bordered list whose children are rows separated by hairlines. */
export function ListPanel({ children, className }: { children: ReactNode; className?: string }) {
    return <div className={cn('divide-y overflow-hidden rounded-xl border bg-card', className)}>{children}</div>;
}

/**
 * Column names on top of a ListPanel. The slots mirror EntityRow (leading,
 * title, meta, trailing): give each label the same width class as the row's
 * cell so they line up. Hidden on a phone, where rows show one column.
 */
export function ListHeader({ leading, title, meta, trailing }: { leading?: ReactNode; title: ReactNode; meta?: ReactNode; trailing?: ReactNode }) {
    return (
        <div className="hidden items-center gap-3 bg-muted/40 px-4 py-2 text-xs font-medium text-muted-foreground sm:flex">
            {leading && <div className="flex shrink-0 items-center">{leading}</div>}
            <div className="min-w-0 flex-1">{title}</div>
            {meta && <div className="flex shrink-0 items-center gap-3">{meta}</div>}
            {trailing && <div className="flex shrink-0 items-center gap-2">{trailing}</div>}
        </div>
    );
}

/** One entity in a list: leading visual, title, secondary line, trailing meta. Clickable when given an href. */
export function EntityRow({
    href,
    leading,
    title,
    subtitle,
    meta,
    trailing,
    className,
}: {
    href?: string;
    leading?: ReactNode;
    title: ReactNode;
    subtitle?: ReactNode;
    meta?: ReactNode;
    trailing?: ReactNode;
    className?: string;
}) {
    const body = (
        <>
            {leading && <div className="flex shrink-0 items-center">{leading}</div>}
            <div className="min-w-0 flex-1">
                {/* Two lines on a phone, where the status takes room; one line with "…" from sm up. */}
                <div className="line-clamp-2 text-sm font-medium break-words sm:line-clamp-1">{title}</div>
                {subtitle && <div className="truncate text-xs text-muted-foreground">{subtitle}</div>}
            </div>
            {meta && <div className="hidden shrink-0 items-center gap-3 text-xs text-muted-foreground sm:flex">{meta}</div>}
            {trailing && <div className="flex shrink-0 items-center gap-2">{trailing}</div>}
        </>
    );
    const classes = cn('flex items-center gap-3 px-4 py-2.5', href && 'transition-colors hover:bg-accent/60', className);

    return href ? (
        <Link href={href} className={classes}>
            {body}
        </Link>
    ) : (
        <div className={classes}>{body}</div>
    );
}

/** A headline number with its label and a quiet explanation line. */
export function MetricCard({
    icon: Icon,
    value,
    label,
    description,
    href,
    tone,
}: {
    icon: LucideIcon;
    value: ReactNode;
    label: string;
    description?: ReactNode;
    href?: string;
    tone?: 'warning' | 'danger';
}) {
    const inner = (
        <div className={cn('flex h-full flex-col gap-1 rounded-xl px-5 py-4 transition-colors', href && 'hover:bg-accent/60')}>
            <div className="flex items-start justify-between gap-3">
                <span
                    className={cn(
                        'text-3xl font-semibold tracking-tight tabular-nums',
                        tone === 'warning' && 'text-status-warning',
                        tone === 'danger' && 'text-status-danger',
                    )}
                >
                    {value}
                </span>
                <Icon className="mt-1 size-4 text-muted-foreground" />
            </div>
            <span className="text-sm font-medium">{label}</span>
            {description && <span className="text-xs text-muted-foreground">{description}</span>}
        </div>
    );

    return href ? <Link href={href}>{inner}</Link> : inner;
}

/** Right-hand properties column of a detail page (label on the left, value on the right). */
export function Properties({ title = 'Propriedades', children, className }: { title?: string; children: ReactNode; className?: string }) {
    return (
        <aside className={cn('flex flex-col gap-1 rounded-xl border bg-card p-4', className)}>
            <h2 className="mb-2 text-sm font-semibold">{title}</h2>
            {children}
        </aside>
    );
}

export function Property({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid grid-cols-[7.5rem_1fr] items-start gap-3 py-1.5 text-sm">
            <span className="text-muted-foreground">{label}</span>
            <span className="min-w-0 break-words">{children ?? <span className="text-muted-foreground">—</span>}</span>
        </div>
    );
}

// "Agente de Finanças" reads as "FI", not "AD".
const skipWords = new Set(['agente', 'de', 'da', 'do', 'das', 'dos', 'e', 'of', 'the']);

/** Initials in a soft rounded square; agents get a bot-tinted variant. */
export function Monogram({ name, agent = false, className }: { name: string; agent?: boolean; className?: string }) {
    const words = name
        .split(/\s+/)
        .filter((part) => /^\p{L}/u.test(part) && !skipWords.has(part.toLowerCase()))
        .slice(0, 2);
    const letters = (words.length === 1 ? words[0].slice(0, 2) : words.map((part) => part[0]).join('')).toUpperCase();

    return (
        <span
            className={cn(
                'inline-flex size-7 shrink-0 items-center justify-center rounded-lg text-[11px] font-semibold',
                agent ? 'bg-primary/12 text-primary' : 'bg-muted text-muted-foreground',
                className,
            )}
            aria-hidden="true"
        >
            {letters || '?'}
        </span>
    );
}
