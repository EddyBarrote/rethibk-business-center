import { cn } from '@/lib/utils';

/** The product name everywhere the brand is spelled out. */
export const PRODUCT_NAME = 'Rethink Business Center';

/**
 * The product mark: an R drawn as one stroke whose leg reaches for a node,
 * a thought turning into action. Sized by className like any box.
 */
export function RethinkMark({ className }: { className?: string }) {
    return (
        <div className={cn('flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground', className)}>
            <svg viewBox="0 0 32 32" fill="none" aria-hidden="true" className="size-[72%]">
                <path
                    d="M10.5 24V8.5h6.25a4.75 4.75 0 0 1 0 9.5H10.5"
                    stroke="currentColor"
                    strokeWidth="3"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
                <path d="M16 18l5 5.5" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                <circle cx="24" cy="9" r="2.25" fill="currentColor" opacity="0.7" />
            </svg>
        </div>
    );
}
