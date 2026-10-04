import { Loader2 } from 'lucide-react';

import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';
import type { RunStatus } from '@/types';

const tones: Record<RunStatus, string> = {
    queued: 'border-transparent bg-slate-100 text-slate-700',
    running: 'border-transparent bg-sky-100 text-sky-800',
    awaiting_approval: 'border-transparent bg-amber-100 text-amber-800',
    completed: 'border-transparent bg-emerald-100 text-emerald-800',
    failed: 'border-transparent bg-rose-100 text-rose-800',
    cancelled: 'border-transparent bg-slate-100 text-slate-500',
};

export function RunStatusBadge({ status, label }: { status: RunStatus; label: string }) {
    return (
        <Badge className={cn(tones[status])}>
            {(status === 'running' || status === 'queued') && <Loader2 className="animate-spin" />}
            {label}
        </Badge>
    );
}
