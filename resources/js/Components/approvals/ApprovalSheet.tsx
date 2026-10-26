import { ApprovalPanel } from '@/Components/approvals/ApprovalPanel';
import type { ApprovalSummary } from '@/types';

/**
 * The approvals page's side panel over a short list of pending approvals (the
 * dashboard's "Precisa de si", the home screen's "À sua espera"), so a person
 * decides without leaving the screen. Deciding moves on to the next one; the
 * list itself comes back fresh from the redirect, without the decided one.
 */
export function ApprovalSheet({
    approvals,
    openId,
    onOpenChange,
}: {
    approvals: ApprovalSummary[];
    openId: number | null;
    onOpenChange: (id: number | null) => void;
}) {
    const index = approvals.findIndex((approval) => approval.id === openId);
    const shown = index === -1 ? null : approvals[index];
    const go = (to: number) => (to >= 0 && to < approvals.length ? () => onOpenChange(approvals[to].id) : null);

    return (
        <ApprovalPanel
            approval={shown}
            loading={false}
            position={shown && approvals.length > 1 ? { index, total: approvals.length } : null}
            onPrevious={go(index - 1)}
            onNext={go(index + 1)}
            onClose={() => onOpenChange(null)}
            // The one after (or before) is still pending once this one leaves the list; none left closes the panel.
            onDecided={() => onOpenChange((approvals[index + 1] ?? approvals[index - 1])?.id ?? null)}
        />
    );
}
