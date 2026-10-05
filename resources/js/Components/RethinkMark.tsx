import { cn } from '@/lib/utils';

/** The product name everywhere the brand is spelled out. */
export const PRODUCT_NAME = 'Rethink Business Center';

/**
 * The product mark: a workflow of three steps joined by one path, with the
 * spark of AI beside it. Sized by className like any box.
 */
export function RethinkMark({ className }: { className?: string }) {
    return (
        <div className={cn('flex size-8 shrink-0 items-center justify-center rounded-md bg-primary text-primary-foreground', className)}>
            <svg viewBox="0 0 32 32" fill="none" aria-hidden="true" className="size-[78%]">
                <path
                    d="M8 9h4a4 4 0 0 1 4 4v6a4 4 0 0 0 4 4h4"
                    stroke="currentColor"
                    strokeWidth="2.6"
                    strokeLinecap="round"
                    strokeLinejoin="round"
                />
                <circle cx="8" cy="9" r="3" fill="currentColor" />
                <circle cx="16" cy="16" r="3" fill="currentColor" />
                <circle cx="24" cy="23" r="3" fill="currentColor" />
                <path d="M24 4.5q.55 3.45 4 4q-3.45.55-4 4q-.55-3.45-4-4q3.45-.55 4-4z" fill="currentColor" />
            </svg>
        </div>
    );
}
