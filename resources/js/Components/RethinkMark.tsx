import { cn } from '@/lib/utils';

export function RethinkMark({ className }: { className?: string }) {
    return (
        <div className={cn('flex size-8 items-center justify-center rounded-md bg-primary font-bold text-primary-foreground', className)}>
            R
        </div>
    );
}
