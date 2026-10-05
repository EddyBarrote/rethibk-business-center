import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { type ReactNode, useEffect, useMemo, useState } from 'react';

import { buttonVariants } from '@/Components/ui/button';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

/*
 * One pager for every list: "21–40 de 77" on the left, previous, numbered pages
 * with gaps and next on the right. Server lists pass the Laravel paginator;
 * lists that arrive whole page in the browser with usePaged().
 */

const count = new Intl.NumberFormat('pt-PT');

/** Pages to show around the current one: 1 … 4 5 [6] 7 8 … 20. */
export function pageWindow(current: number, last: number): (number | 'gap')[] {
    if (last <= 7) {
        return Array.from({ length: last }, (_, index) => index + 1);
    }

    const start = Math.max(2, Math.min(current - 1, last - 4));
    const end = Math.min(last - 1, Math.max(current + 1, 5));
    const pages: (number | 'gap')[] = [1];

    if (start > 2) {
        pages.push('gap');
    }
    for (let page = start; page <= end; page++) {
        pages.push(page);
    }
    if (end < last - 1) {
        pages.push('gap');
    }
    pages.push(last);

    return pages;
}

function PagerItem({
    href,
    onSelect,
    active,
    disabled,
    label,
    children,
}: {
    href?: string | null;
    onSelect?: () => void;
    active?: boolean;
    disabled?: boolean;
    label?: string;
    children: ReactNode;
}) {
    const classes = cn(
        buttonVariants({ variant: active ? 'outline' : 'ghost', size: 'sm' }),
        'h-8 min-w-8 px-2 font-mono text-xs tabular-nums',
        active && 'pointer-events-none font-semibold',
        disabled && 'pointer-events-none opacity-40',
    );

    if (disabled || (!href && !onSelect)) {
        return (
            <span className={classes} aria-disabled="true" aria-label={label}>
                {children}
            </span>
        );
    }

    return href ? (
        <Link href={href} className={classes} aria-current={active ? 'page' : undefined} aria-label={label}>
            {children}
        </Link>
    ) : (
        <button type="button" onClick={onSelect} className={classes} aria-current={active ? 'page' : undefined} aria-label={label}>
            {children}
        </button>
    );
}

/** The bar itself; `hrefFor` (server lists) or `onPage` (browser lists) moves between pages. */
export function PaginationBar({
    current,
    last,
    from,
    to,
    total,
    noun,
    hrefFor,
    onPage,
    className,
}: {
    current: number;
    last: number;
    from: number | null;
    to: number | null;
    total: number;
    noun?: [singular: string, plural: string];
    hrefFor?: (page: number) => string | null;
    onPage?: (page: number) => void;
    className?: string;
}) {
    if (total === 0) {
        return null;
    }

    const go = (page: number) => ({ href: hrefFor?.(page), onSelect: onPage ? () => onPage(page) : undefined });
    const what = noun ? ` ${total === 1 ? noun[0] : noun[1]}` : '';

    return (
        <nav aria-label="Paginação" className={cn('flex flex-col items-center justify-between gap-3 text-sm sm:flex-row', className)}>
            <p className="text-xs text-muted-foreground tabular-nums">
                {last > 1 ? (
                    <>
                        <span className="font-mono text-foreground">
                            {count.format(from ?? 0)}–{count.format(to ?? 0)}
                        </span>{' '}
                        de <span className="font-mono text-foreground">{count.format(total)}</span>
                        {what}
                    </>
                ) : (
                    <>
                        <span className="font-mono text-foreground">{count.format(total)}</span>
                        {what}
                    </>
                )}
            </p>
            {last > 1 && (
                <div className="flex items-center gap-0.5">
                    <PagerItem {...go(current - 1)} disabled={current <= 1} label="Página anterior">
                        <ChevronLeft />
                        <span className="hidden font-sans sm:inline">Anterior</span>
                    </PagerItem>
                    {pageWindow(current, last).map((page, index) =>
                        page === 'gap' ? (
                            <span key={`gap-${index}`} className="px-1 text-xs text-muted-foreground" aria-hidden="true">
                                …
                            </span>
                        ) : (
                            <PagerItem key={page} {...go(page)} active={page === current} label={`Página ${page}`}>
                                {page}
                            </PagerItem>
                        ),
                    )}
                    <PagerItem {...go(current + 1)} disabled={current >= last} label="Página seguinte">
                        <span className="hidden font-sans sm:inline">Seguinte</span>
                        <ChevronRight />
                    </PagerItem>
                </div>
            )}
        </nav>
    );
}

/** URL of page N of the current screen, keeping its filters even when the paginator was built without them. */
function pageHref(target: number): string {
    const url = new URL(window.location.href);

    if (target <= 1) {
        url.searchParams.delete('page');
    } else {
        url.searchParams.set('page', String(target));
    }

    return url.pathname + url.search;
}

/** Pager for a Laravel paginator from the server. */
export function Pagination<T>({ page, noun, className }: { page: Paginated<T>; noun?: [string, string]; className?: string }) {
    return (
        <PaginationBar
            current={page.current_page}
            last={page.last_page}
            from={page.from ?? null}
            to={page.to ?? null}
            total={page.total}
            noun={noun}
            hrefFor={pageHref}
            className={className}
        />
    );
}

/** Pages a list that is already in the browser; goes back to page 1 when the list changes size (a filter). */
export function usePaged<T>(items: T[], perPage = 25) {
    const [page, setPage] = useState(1);
    const last = Math.max(1, Math.ceil(items.length / perPage));

    useEffect(() => setPage(1), [items.length]);

    const current = Math.min(page, last);
    const slice = useMemo(() => items.slice((current - 1) * perPage, current * perPage), [items, current, perPage]);

    return {
        items: slice,
        /** Spread on <PaginationBar />. */
        pager: {
            current,
            last,
            from: items.length === 0 ? null : (current - 1) * perPage + 1,
            to: Math.min(current * perPage, items.length),
            total: items.length,
            onPage: setPage,
        },
    };
}
