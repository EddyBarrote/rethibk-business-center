import { cn } from '@/lib/utils';

const labels = ['Observa e organiza', 'Sugere', 'Executa com aprovação', 'Executa dentro de limites', 'Executa e reporta'];

/**
 * The autonomy ladder of section 12.1, N0 to N4: a machine value, so monospace,
 * with five ticks that fill as autonomy grows.
 */
export function AutonomyBadge({ level, withLabel = false }: { level: number; withLabel?: boolean }) {
    return (
        <span className="inline-flex h-5 shrink-0 items-center gap-1.5 rounded-full bg-muted px-2 text-xs text-muted-foreground" title={labels[level]}>
            <span className="font-mono font-medium text-foreground">N{level}</span>
            <span className="flex gap-0.5" aria-hidden="true">
                {[0, 1, 2, 3, 4].map((tick) => (
                    <span key={tick} className={cn('h-2 w-1 rounded-full', tick <= level ? 'bg-primary' : 'bg-border')} />
                ))}
            </span>
            {withLabel && <span>{labels[level]}</span>}
        </span>
    );
}
