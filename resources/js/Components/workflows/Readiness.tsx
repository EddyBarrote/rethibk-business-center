import type { ReactNode } from 'react';

import { StatusBadge, StatusDot, toneFill } from '@/Components/Status';
import { cn } from '@/lib/utils';
import {
    blocks,
    type FlowRow,
    type Readiness,
    readinessLabel,
    readinessShort,
    readinessTone,
    type ReadinessState,
    type ReadinessSummary,
    type WfGraph,
} from '@/lib/workflows';

/*
 * How the map, the list and the canvas show what each block will do with what
 * the agents have (docs/DECISOES.md, "Fluxos de trabalho").
 */

export function ReadinessBadge({ state, short = false, className }: { state: ReadinessState; short?: boolean; className?: string }) {
    return (
        <StatusBadge tone={readinessTone[state]} className={className}>
            {short ? readinessShort[state] : readinessLabel[state]}
        </StatusBadge>
    );
}

/** A thin bar with one segment per work block, coloured by what will happen to it. */
export function ReadinessBar({ summary, className }: { summary: ReadinessSummary; className?: string }) {
    const parts = (['alone', 'chief', 'person', 'missing'] as const).filter((state) => summary[state] > 0);

    if (parts.length === 0) {
        return <div className={cn('h-1.5 rounded-full bg-muted', className)} />;
    }

    return (
        <div className={cn('flex h-1.5 gap-0.5 overflow-hidden rounded-full', className)} aria-hidden="true">
            {parts.map((state) => (
                <span key={state} className={cn('h-full', toneFill[readinessTone[state]])} style={{ flex: summary[state] }} />
            ))}
        </div>
    );
}

export function ReadinessLegend({ className }: { className?: string }) {
    return (
        <div className={cn('flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground', className)}>
            {(['alone', 'chief', 'person', 'missing'] as const).map((state) => (
                <span key={state} className="inline-flex items-center gap-1.5">
                    <StatusDot tone={readinessTone[state]} pulse={false} />
                    {readinessLabel[state]}
                </span>
            ))}
        </div>
    );
}

/** The capability, skill or person a block uses, as small chips. */
export function BlockChips({
    data,
    agents,
    people,
}: {
    data: WfGraph['nodes'][number]['data'];
    agents?: { id: number; name: string }[];
    people?: { id: number; name: string }[];
}) {
    const chips: { text: string; mono?: boolean }[] = [];

    if (data.agent_id) {
        chips.push({ text: `passa ao ${agents?.find((agent) => agent.id === data.agent_id)?.name ?? 'agente'}` });
    }

    if (data.capability) {
        chips.push({ text: data.capability, mono: true });
    }

    if (data.skill) {
        chips.push({ text: `skill ${data.skill}` });
    }

    if (data.user_id) {
        chips.push({ text: people?.find((person) => person.id === data.user_id)?.name ?? 'pessoa' });
    }

    if (data.hours) {
        chips.push({ text: `${data.hours} h` });
    }

    if (data.max) {
        chips.push({ text: `máx. ${data.max}` });
    }

    if (chips.length === 0) {
        return null;
    }

    return (
        <div className="mt-1 flex flex-wrap gap-1">
            {chips.map((chip) => (
                <span key={chip.text} className={cn('rounded-md bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground', chip.mono && 'font-mono')}>
                    {chip.text}
                </span>
            ))}
        </div>
    );
}

/**
 * The flow as an indented list, each block with what will happen to it.
 */
export function FlowSteps({
    rows,
    readiness,
    selected,
    onSelect,
    agents,
    people,
    actions,
}: {
    rows: FlowRow[];
    readiness: Record<string, Readiness>;
    selected?: string | null;
    onSelect?: (id: string) => void;
    agents?: { id: number; name: string }[];
    people?: { id: number; name: string }[];
    actions?: (row: FlowRow) => ReactNode;
}) {
    let n = 0;

    return (
        <ol className="divide-y">
            {rows.map((row) => {
                const meta = blocks[row.node.type];
                const Icon = meta.icon;
                const ready = readiness[row.node.id];
                const work = ready && ready.state !== 'flow';

                if (row.node.type !== 'trigger' && row.node.type !== 'end') {
                    n++;
                }

                return (
                    <li key={row.node.id}>
                        {row.branch && (
                            <div
                                className="px-4 pt-2 text-[11px] font-medium tracking-wide text-muted-foreground uppercase"
                                style={{ paddingLeft: 16 + row.depth * 22 }}
                            >
                                {row.branch}
                            </div>
                        )}
                        <div
                            role={onSelect ? 'button' : undefined}
                            tabIndex={onSelect ? 0 : undefined}
                            onClick={() => onSelect?.(row.node.id)}
                            onKeyDown={(event) => event.key === 'Enter' && onSelect?.(row.node.id)}
                            className={cn(
                                'group flex items-start gap-3 py-2.5 pr-4 transition-colors',
                                onSelect && 'cursor-pointer hover:bg-accent/50',
                                selected === row.node.id && 'bg-accent/70',
                            )}
                            style={{ paddingLeft: 16 + row.depth * 22 }}
                        >
                            <span className={cn('mt-0.5 inline-flex size-6 shrink-0 items-center justify-center rounded-md', meta.swatch)}>
                                <Icon className="size-3.5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-baseline gap-x-2 text-sm font-medium">
                                    {row.node.type !== 'trigger' && row.node.type !== 'end' && (
                                        <span className="font-mono text-xs text-muted-foreground">{n}</span>
                                    )}
                                    <span className="break-words">{row.node.data.label || meta.label}</span>
                                </div>
                                <BlockChips data={row.node.data} agents={agents} people={people} />
                                {ready?.state === 'missing' && ready.reasons[0] && (
                                    <p className="mt-1 text-xs text-status-danger">{ready.reasons[0]}</p>
                                )}
                            </div>
                            <div className="flex shrink-0 items-center gap-2">
                                {work && <ReadinessBadge state={ready.state} />}
                                {actions?.(row)}
                            </div>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
