import { ShieldCheck } from 'lucide-react';

/** Marks the sign-in screens of the super admin console. */
export function AdminBadge() {
    return (
        <div className="mb-8 inline-flex items-center gap-1.5 rounded-full border bg-muted/60 px-2.5 py-1 text-xs font-medium text-muted-foreground">
            <ShieldCheck className="size-3.5 text-primary" />
            Super administrador
        </div>
    );
}
