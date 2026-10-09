import { useForm } from '@inertiajs/react';
import { Check, Loader2, X } from 'lucide-react';
import { useState } from 'react';

import { FormDialog } from '@/Components/Dialogs';
import { Field } from '@/Components/Field';
import { StatusBadge, approvalTone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Textarea } from '@/Components/ui/textarea';

import type { Notice } from './meta';

/**
 * Approve or reject an approval right from its notification; once decided,
 * who decided it. Rejecting asks why, as on the approvals page.
 */
export function ApprovalActions({ approval, title, compact = false }: { approval: NonNullable<Notice['approval']>; title: string; compact?: boolean }) {
    const [rejecting, setRejecting] = useState(false);
    const form = useForm({ note: '' });
    const decide = (action: 'approve' | 'reject') =>
        form.post(`/approvals/${approval.id}/${action}`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setRejecting(false);
            },
        });

    if (!approval.can_decide) {
        return (
            <StatusBadge tone={approvalTone(approval.status)} title={approval.decided_by ? `Por ${approval.decided_by}` : undefined}>
                {approval.status === 'pending' ? 'À espera de decisão' : approval.status_label}
                {approval.decided_by && approval.status !== 'pending' && <span className="text-muted-foreground"> · {approval.decided_by}</span>}
            </StatusBadge>
        );
    }

    return (
        <>
            <div className="flex items-center gap-1.5">
                <Button size={compact ? 'xs' : 'sm'} disabled={form.processing} onClick={() => decide('approve')}>
                    {form.processing && !rejecting ? <Loader2 className="animate-spin" /> : <Check />}
                    Aprovar
                </Button>
                <Button size={compact ? 'xs' : 'sm'} variant="outline" disabled={form.processing} onClick={() => setRejecting(true)}>
                    <X />
                    Rejeitar
                </Button>
            </div>
            <FormDialog
                open={rejecting}
                onOpenChange={(open) => {
                    setRejecting(open);
                    if (!open) {
                        form.clearErrors();
                    }
                }}
                title="Rejeitar esta acção?"
                description={
                    <>
                        O agente fica a saber porquê e não executa: <span className="text-foreground">{title}</span>
                    </>
                }
                submitLabel="Rejeitar"
                processing={form.processing}
                disabled={form.data.note.trim() === ''}
                onSubmit={() => decide('reject')}
                size="sm"
            >
                <Field id={`notice-reject-${approval.id}`} label="Motivo" error={form.errors.note} hint="O agente lê o motivo e ajusta o que faz a seguir.">
                    <Textarea
                        id={`notice-reject-${approval.id}`}
                        rows={3}
                        autoFocus
                        value={form.data.note}
                        onChange={(event) => form.setData('note', event.target.value)}
                    />
                </Field>
            </FormDialog>
        </>
    );
}
