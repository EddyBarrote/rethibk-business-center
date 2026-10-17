import { Bot } from 'lucide-react';
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

/**
 * The agent's face with the agent symbol (the robot of Agentes) on its corner,
 * where something an agent wrote sits beside what people wrote, such as a
 * draft under an email: initials alone read as a person.
 */
export function AgentMark({ name, url, className }: { name: string; url?: string | null; className?: string }) {
    return (
        <span className="relative inline-flex shrink-0" title={`Agente: ${name}`}>
            <AgentAvatar name={name} url={url} className={className} />
            <span className="absolute -right-1 -bottom-1 inline-flex size-3.5 items-center justify-center rounded-full bg-primary text-primary-foreground ring-2 ring-background">
                <Bot className="size-2.5" aria-hidden="true" />
            </span>
        </span>
    );
}
