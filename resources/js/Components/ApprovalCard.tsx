import { Link, useForm } from '@inertiajs/react';
import { Check, Lock, X } from 'lucide-react';
import { useState } from 'react';

import { AutonomyBadge } from '@/Components/AutonomyBadge';
import { approvalTone, StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';
import { ago, dateTime } from '@/lib/format';
import type { ApprovalSummary } from '@/types';

const executionLabel = { not_executed: 'por executar', executed: 'executada', failed: 'falhou' };

/**
 * One approval: what the agent wants to do, why it needs a human, and the
 * decision (section 12.2).
 */
export function ApprovalCard({ approval, compact = false }: { approval: ApprovalSummary; compact?: boolean }) {
    const [showPayload, setShowPayload] = useState(false);
    const form = useForm({ note: '' });

    const decide = (action: 'approve' | 'reject') =>
        form.post(`/approvals/${approval.id}/${action}`, { preserveScroll: true, onSuccess: () => form.reset() });

    return (
        <div className="grid gap-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0 space-y-1">
                    <p className="text-sm font-medium">{approval.action_summary}</p>
                    <p className="text-xs text-muted-foreground">
                        <Link href={`/agents/${approval.agent.id}`} className="hover:underline">
                            {approval.agent.name}
                        </Link>{' '}
                        · <span className="font-mono">{approval.action_type}</span> ·{' '}
                        <Link href={`/runs/${approval.run_id}`} className="hover:underline">
                            execução <span className="font-mono">#{approval.run_id}</span>
                        </Link>{' '}
                        · <span title={dateTime(approval.created_at)}>{ago(approval.created_at)}</span>
                    </p>
                </div>
                <div className="flex items-center gap-1.5">
                    <StatusBadge tone={approvalTone(approval.status)}>{approval.status_label}</StatusBadge>
                    {approval.status === 'approved' && (
                        <StatusBadge tone={approval.execution_status === 'failed' ? 'danger' : approval.execution_status === 'executed' ? 'success' : 'idle'} dot={false}>
                            {executionLabel[approval.execution_status]}
                        </StatusBadge>
                    )}
                </div>
            </div>

            <div className="flex flex-wrap items-center gap-2 text-xs">
                {approval.ceiling_reason ? (
                    <StatusBadge tone="danger" dot={false}>
                        <Lock className="size-3" />
                        Tecto absoluto: {approval.ceiling_reason}
                    </StatusBadge>
                ) : (
                    <span className="flex items-center gap-1.5 text-muted-foreground">
                        O agente está em <AutonomyBadge level={approval.agent_level} /> e esta acção pede <AutonomyBadge level={approval.required_level} />
                    </span>
                )}
                <button type="button" className="text-muted-foreground underline-offset-2 hover:underline" onClick={() => setShowPayload(!showPayload)}>
                    {showPayload ? 'Esconder detalhes' : 'Ver detalhes'}
                </button>
            </div>

            {showPayload && <pre className="max-h-64 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs">{JSON.stringify(approval.payload, null, 2)}</pre>}

            {approval.decided_by && (
                <p className="text-sm text-muted-foreground">
                    {approval.status_label} por {approval.decided_by} em {dateTime(approval.decided_at)}
                    {approval.decision_note && <>: “{approval.decision_note}”</>}
                </p>
            )}

            {approval.execution_result && approval.execution_status !== 'not_executed' && !compact && (
                <pre className="max-h-40 overflow-auto rounded-md bg-muted p-3 text-xs whitespace-pre-wrap">{approval.execution_result.content}</pre>
            )}

            {approval.can_decide && (
                <div className="grid gap-2">
                    <Textarea rows={2} placeholder="Nota (obrigatória para rejeitar)" value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                    {form.errors.note && <p className="text-sm text-destructive">{form.errors.note}</p>}
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" size="sm" disabled={form.processing} onClick={() => decide('reject')}>
                            <X />
                            Rejeitar
                        </Button>
                        <Button size="sm" disabled={form.processing} onClick={() => decide('approve')}>
                            <Check />
                            Aprovar
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}
