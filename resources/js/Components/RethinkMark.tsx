import { cn } from '@/lib/utils';

/** The product name everywhere the brand is spelled out (docs/DECISOES.md, "Nome e logótipo: Rethink.OS"). */
export const PRODUCT_NAME = 'Rethink.OS';

/**
 * The product mark: a grid of four seats, three taken by people and one by an
 * agent (the spark), because people and agents work side by side. Sized by
 * className like any box; public/favicon.svg is the same drawing.
 */
export function RethinkMark({ className }: { className?: string }) {
    return (
        <div className={cn('flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground', className)}>
            <svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true" className="size-full">
                <circle cx="10.5" cy="10.5" r="3.6" />
                <circle cx="21.5" cy="10.5" r="3.6" />
                <circle cx="10.5" cy="21.5" r="3.6" />
                <path d="M21.5 15.6q.7 5.2 5.9 5.9q-5.2.7-5.9 5.9q-.7-5.2-5.9-5.9q5.2-.7 5.9-5.9z" />
            </svg>
        </div>
    );
}

/** The name as a wordmark: "Rethink" in the text colour and ".OS" in the brand colour, like Rethink.ERP. */
export function RethinkWordmark({ className }: { className?: string }) {
    return (
        <span className={cn('font-semibold tracking-tight', className)}>
            Rethink<span className="text-primary">.OS</span>
        </span>
    );
}
