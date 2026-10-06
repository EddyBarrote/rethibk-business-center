import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Designed empty state. Screens never render null for "no data" (section 11.3);
 * the text says what to do first.
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    example,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    action?: ReactNode;
    /** A faded sample of what will appear here, so the first one is easy to picture. */
    example?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-10 text-center">
            <Icon className="size-5 text-muted-foreground" />
            <div className="space-y-1">
                <p className="text-sm font-medium">{title}</p>
                <p className="max-w-md text-sm text-muted-foreground">{description}</p>
            </div>
            {action}
            {example && (
                <div className="mt-2 w-full max-w-md text-left" aria-hidden="true">
                    <p className="mb-1.5 text-[11px] font-medium tracking-wide text-muted-foreground uppercase">Por exemplo</p>
                    <div className="pointer-events-none rounded-xl border bg-card px-4 py-3 opacity-70 select-none">{example}</div>
                </div>
            )}
        </div>
    );
}
