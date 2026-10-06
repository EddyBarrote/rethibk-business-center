import { Link, router } from '@inertiajs/react';
import { ChevronDown, RefreshCw } from 'lucide-react';
import { useState } from 'react';

import { CategoryBadge } from '@/Components/CategoryBadge';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { NativeSelect } from '@/Components/ui/native-select';
import { factValue } from '@/lib/approvals';
import type { OpenConversation, ThreadMessage } from '@/lib/email';
import { dateTime, deadline, fieldLabel } from '@/lib/format';
import { cn } from '@/lib/utils';

const priorities: Record<string, { label: string; tone: Tone }> = {
    urgent: { label: 'Urgente', tone: 'danger' },
    high: { label: 'Alta', tone: 'warning' },
    normal: { label: 'Normal', tone: 'idle' },
    low: { label: 'Baixa', tone: 'idle' },
};

function Row({ label, children }: { label: string; children: React.ReactNode }) {
    if (children === null || children === undefined || children === '') {
        return null;
    }

    return (
        <div className="grid grid-cols-[7rem_minmax(0,1fr)] gap-2 text-xs">
            <span className="text-muted-foreground">{label}</span>
            <span className="min-w-0">{children}</span>
        </div>
    );
}

/**
 * What the agent made of an email: its summary, category, who it went to,
 * the task it opened, the fields it took out. Correcting the category is kept
 * for the agent.
 */
export function TriagePanel({ open, message }: { open: OpenConversation; message: ThreadMessage }) {
    const [expanded, setExpanded] = useState(false);
    const [category, setCategory] = useState(message.category ?? '');
    const priority = message.priority ? priorities[message.priority] : null;
    const extracted = Object.entries(message.extracted ?? {});

    return (
        <section className="rounded-xl border bg-card" aria-label="Triagem">
            <button type="button" onClick={() => setExpanded((v) => !v)} className="flex w-full items-start gap-3 px-4 py-3 text-left">
                <div className="min-w-0 flex-1">
                    <div className="mb-1 flex flex-wrap items-center gap-2">
                        <span className="text-[11px] font-medium tracking-widest text-muted-foreground uppercase">Resumo do agente</span>
                        {message.category && <CategoryBadge category={message.category} label={message.category_label} />}
                        {priority && priority.tone !== 'idle' && <StatusBadge tone={priority.tone}>{priority.label}</StatusBadge>}
                    </div>
                    <p className={cn('text-sm leading-relaxed', !expanded && 'line-clamp-2')}>{message.summary ?? 'Ainda sem resumo.'}</p>
                </div>
                <ChevronDown className={cn('mt-1 size-4 shrink-0 text-muted-foreground transition-transform', expanded && 'rotate-180')} />
            </button>

            {expanded && (
                <div className="flex flex-col gap-2 border-t px-4 py-3">
                    <Row label="Encaminhado a">{message.routed_to}</Row>
                    <Row label="Departamento">{message.department}</Row>
                    <Row label="Prazo">
                        {message.deadline_at && (
                            <span className="tabular-nums" title={dateTime(message.deadline_at)}>
                                {deadline(message.deadline_at)}
                            </span>
                        )}
                    </Row>
                    <Row label="Lead no ERP">{message.erp_lead_id && <span className="font-mono">{message.erp_lead_id}</span>}</Row>
                    <Row label="Tarefa">
                        {open.tasks.length > 0 &&
                            open.tasks.map((task) => (
                                <Link key={task.id} href={`/tasks/${task.id}`} className="block truncate hover:underline" title={task.title}>
                                    <span className="font-mono">{task.ref}</span> {task.title}
                                </Link>
                            ))}
                    </Row>
                    <Row label="Execução">
                        {message.agent_run_id && (
                            <Link href={`/runs/${message.agent_run_id}`} className="font-mono text-primary hover:underline">
                                #{message.agent_run_id}
                            </Link>
                        )}
                    </Row>
                    {extracted.map(([key, value]) => (
                        <Row key={key} label={fieldLabel(key)}>
                            {factValue(key, value) ?? JSON.stringify(value)}
                        </Row>
                    ))}
                    {open.follow_ups.map((f) => (
                        <Row key={f.id} label="Seguimento">
                            <span className={f.done ? 'text-muted-foreground line-through' : ''}>
                                {f.title} · {deadline(f.due_at)}
                            </span>
                        </Row>
                    ))}

                    {message.direction === 'inbound' && (
                        <div className="mt-1 flex flex-wrap items-center gap-2 border-t pt-3">
                            <NativeSelect className="h-8 min-w-0 flex-1 text-sm" value={category} onChange={(e) => setCategory(e.target.value)} aria-label="Categoria">
                                {open.categories.map((c) => (
                                    <option key={c.value} value={c.value}>
                                        {c.label}
                                    </option>
                                ))}
                            </NativeSelect>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={!category || category === message.category}
                                onClick={() => router.put(`/inbox/${message.id}/category`, { category }, { preserveScroll: true, preserveState: true })}
                            >
                                Corrigir categoria
                            </Button>
                            <Button size="sm" variant="ghost" onClick={() => router.post(`/inbox/${message.id}/retriage`, {}, { preserveScroll: true, preserveState: true })}>
                                <RefreshCw />
                                Triar de novo
                            </Button>
                        </div>
                    )}
                </div>
            )}
        </section>
    );
}
