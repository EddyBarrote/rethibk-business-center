import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';

const colours: Record<string, string> = {
    lead: 'bg-emerald-500/12 text-emerald-700 dark:text-emerald-300',
    tender: 'bg-violet-500/12 text-violet-700 dark:text-violet-300',
    client_rfq: 'bg-indigo-500/12 text-indigo-700 dark:text-indigo-300',
    client_request: 'bg-sky-500/12 text-sky-700 dark:text-sky-300',
    supplier_invoice: 'bg-amber-500/12 text-amber-700 dark:text-amber-300',
    supplier_quote: 'bg-orange-500/12 text-orange-700 dark:text-orange-300',
    bank_statement: 'bg-teal-500/12 text-teal-700 dark:text-teal-300',
    job_application: 'bg-pink-500/12 text-pink-700 dark:text-pink-300',
    internal: 'bg-muted text-muted-foreground',
    newsletter: 'bg-muted text-muted-foreground/80',
    spam: 'bg-red-500/12 text-red-700 dark:text-red-300',
    other: 'bg-muted text-muted-foreground',
};

export function CategoryBadge({ category, label }: { category: string | null; label: string | null }) {
    if (!category) {
        return <Badge variant="outline">Por triar</Badge>;
    }

    return <Badge className={cn('border-transparent', colours[category] ?? colours.other)}>{label}</Badge>;
}

export function PriorityDot({ priority }: { priority: string | null }) {
    if (priority !== 'urgent' && priority !== 'high') {
        return null;
    }

    return (
        <span
            className={cn('inline-block size-2 rounded-full', priority === 'urgent' ? 'bg-status-danger' : 'bg-status-warning')}
            title={priority === 'urgent' ? 'Urgente' : 'Prioridade alta'}
        />
    );
}
