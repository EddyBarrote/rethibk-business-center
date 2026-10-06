import { cn } from '@/lib/utils';

export function InputError({ message, className }: { message?: string; className?: string }) {
    if (!message) {
        return null;
    }

    return <p className={cn('text-sm text-destructive', className)}>{message}</p>;
}
