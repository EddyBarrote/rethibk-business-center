import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types';

/**
 * Whether the person has a permission of the access matrix. Only hides what
 * they cannot use; the server checks every permission again.
 */
export function useCan(): (permission: string) => boolean {
    const { auth } = usePage<SharedProps>().props;

    return (permission) => auth.user?.permissions.includes(permission) ?? false;
}
