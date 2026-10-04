import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

/**
 * Designed empty state. Screens never render null for "no data" (section 11.3).
 */
export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-12 text-center">
            <div className="flex size-11 items-center justify-center rounded-full bg-accent text-accent-foreground">
                <Icon className="size-5" />
            </div>
            <div className="space-y-1">
                <p className="font-medium">{title}</p>
                <p className="max-w-md text-sm text-muted-foreground">{description}</p>
            </div>
            {action}
        </div>
    );
}
