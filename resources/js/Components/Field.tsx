import type { ReactNode } from 'react';

import { InputError } from '@/Components/InputError';
import { Label } from '@/Components/ui/label';
import { cn } from '@/lib/utils';

/**
 * Label, control and validation message, the way every form lays them out.
 */
export function Field({
    id,
    label,
    error,
    hint,
    className,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    hint?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && !error && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}
