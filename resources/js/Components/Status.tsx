import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

/**
 * One status vocabulary for the whole UI (pattern adapted from Paperclip, MIT):
 * every state maps to one of five tones, and badges, dots, rows and charts all
 * use the same tone tokens from app.css.
 */
export type Tone = 'running' | 'success' | 'warning' | 'danger' | 'idle';

const dot: Record<Tone, string> = {
    running: 'bg-status-running',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    danger: 'bg-status-danger',
    idle: 'bg-status-idle',
};

const badge: Record<Tone, string> = {
    running: 'bg-status-running/12 text-status-running ring-status-running/25',
    success: 'bg-status-success/12 text-status-success ring-status-success/25',
    warning: 'bg-status-warning/15 text-[color-mix(in_oklch,var(--status-warning)_70%,var(--foreground))] ring-status-warning/30',
    danger: 'bg-status-danger/12 text-status-danger ring-status-danger/25',
    idle: 'bg-muted text-muted-foreground ring-border',
};

export const toneFill = dot;

export function StatusDot({ tone, pulse = tone === 'running', className }: { tone: Tone; pulse?: boolean; className?: string }) {
    return (
        <span className={cn('relative inline-flex size-2 shrink-0', className)} aria-hidden="true">
            {pulse && <span className={cn('absolute inline-flex size-full animate-ping rounded-full opacity-60', dot[tone])} />}
            <span className={cn('relative inline-flex size-2 rounded-full', dot[tone])} />
        </span>
    );
}

export function StatusBadge({ tone, children, dot: withDot = true, className, title }: { tone: Tone; children: ReactNode; dot?: boolean; className?: string; title?: string }) {
    return (
        <span
            title={title}
            className={cn(
                'inline-flex h-5 w-fit shrink-0 items-center gap-1.5 rounded-full px-2 text-xs font-medium whitespace-nowrap ring-1 ring-inset',
                badge[tone],
                className,
            )}
        >
            {withDot && <StatusDot tone={tone} pulse={tone === 'running'} className="size-1.5 [&>span]:size-1.5" />}
            {children}
        </span>
    );
}

/* Mappings from domain states to tones, so every screen agrees. */

export const runTone = (status: string): Tone =>
    ({ queued: 'idle', running: 'running', awaiting_approval: 'warning', completed: 'success', failed: 'danger', cancelled: 'idle' })[status] as Tone ?? 'idle';

export const agentTone = (status: string, running = false): Tone => (running ? 'running' : ({ active: 'success', draft: 'idle', suspended: 'danger' })[status] as Tone ?? 'idle');

export const approvalTone = (status: string): Tone =>
    ({ pending: 'warning', approved: 'success', executed: 'success', rejected: 'danger', expired: 'idle', failed: 'danger', cancelled: 'idle' })[status] as Tone ??
    'idle';
