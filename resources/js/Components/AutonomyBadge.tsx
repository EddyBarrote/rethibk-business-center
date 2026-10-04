import { Badge } from '@/Components/ui/badge';
import { cn } from '@/lib/utils';

const labels = ['Observa e organiza', 'Sugere', 'Executa com aprovação', 'Executa dentro de limites', 'Executa e reporta'];

const tones = [
    'border-transparent bg-slate-100 text-slate-700',
    'border-transparent bg-sky-100 text-sky-800',
    'border-transparent bg-amber-100 text-amber-800',
    'border-transparent bg-orange-100 text-orange-800',
    'border-transparent bg-rose-100 text-rose-800',
];

/**
 * The autonomy ladder of section 12.1, N0 to N4.
 */
export function AutonomyBadge({ level, withLabel = false }: { level: number; withLabel?: boolean }) {
    return (
        <Badge className={cn(tones[level] ?? tones[0])} title={labels[level]}>
            N{level}
            {withLabel && <span className="font-normal">· {labels[level]}</span>}
        </Badge>
    );
}
