import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';

const colours: Record<string, string> = {
    lead: 'bg-emerald-100 text-emerald-800',
    tender: 'bg-violet-100 text-violet-800',
    client_request: 'bg-sky-100 text-sky-800',
    supplier_invoice: 'bg-amber-100 text-amber-800',
    supplier_quote: 'bg-orange-100 text-orange-800',
    bank_statement: 'bg-teal-100 text-teal-800',
    job_application: 'bg-pink-100 text-pink-800',
    internal: 'bg-slate-100 text-slate-700',
    newsletter: 'bg-slate-100 text-slate-500',
    spam: 'bg-red-100 text-red-700',
    other: 'bg-slate-100 text-slate-700',
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

    return <span className={cn('inline-block size-2 rounded-full', priority === 'urgent' ? 'bg-red-500' : 'bg-amber-500')} title={priority === 'urgent' ? 'Urgente' : 'Prioridade alta'} />;
}
