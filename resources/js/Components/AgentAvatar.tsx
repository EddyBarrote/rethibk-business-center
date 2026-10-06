import { usePage } from '@inertiajs/react';

import { Monogram } from '@/Components/Blocks';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/**
 * An agent's face: its photo when it has one, its initials otherwise. Agents
 * are colleagues, so they appear the same way everywhere. Without a url the
 * photo is found by name among the tenant's agents (shared with every page).
 */
export function AgentAvatar({ name, url, className }: { name: string; url?: string | null; className?: string }) {
    const agents = usePage<SharedProps>().props.sidebar_agents ?? [];
    const src = url === undefined ? agents.find((agent) => agent.name === name)?.avatar_url : url;

    if (!src) {
        return <Monogram name={name} agent className={className} />;
    }

    return <img src={src} alt="" className={cn('inline-block size-7 shrink-0 rounded-lg bg-muted object-cover', className)} />;
}
