import { Link } from '@inertiajs/react';

import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

export function Pagination<T>({ page }: { page: Paginated<T> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav className="flex flex-wrap justify-center gap-1">
            {page.links.map((link, index) =>
                link.url ? (
                    <Link
                        key={index}
                        href={link.url}
                        preserveScroll
                        className={cn('rounded-md px-3 py-1.5 text-sm hover:bg-accent', link.active && 'bg-accent font-medium')}
                        dangerouslySetInnerHTML={{ __html: link.label }}
                    />
                ) : (
                    <span key={index} className="px-3 py-1.5 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: link.label }} />
                ),
            )}
        </nav>
    );
}
