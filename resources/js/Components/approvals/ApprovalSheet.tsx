import { useEffect } from 'react';

import { ApprovalPanel } from '@/Components/approvals/ApprovalPanel';
import type { ApprovalSummary } from '@/types';

/**
 * The approvals page's side panel over a short list of pending approvals (the
 * dashboard's "Precisa de si", the home screen's "À sua espera"), so a person
 * decides without leaving the screen. Docked, it goes in AppLayout's `aside`
 * and the page moves over for it; otherwise it slides over the page. Deciding
 * moves on to the next one; the list comes back fresh from the redirect.
 */
export function ApprovalSheet({
    approvals,
    openId,
    onOpenChange,
    docked = false,
}: {
    approvals: ApprovalSummary[];
    openId: number | null;
    onOpenChange: (id: number | null) => void;
    docked?: boolean;
}) {
    const index = approvals.findIndex((approval) => approval.id === openId);
    const shown = index === -1 ? null : approvals[index];
    const go = (to: number) => (to >= 0 && to < approvals.length ? () => onOpenChange(approvals[to].id) : null);

    // Gone from the list (decided elsewhere, expired): close rather than leave an empty column.
    useEffect(() => {
        if (openId !== null && index === -1) {
            onOpenChange(null);
        }
    }, [openId, index, onOpenChange]);

    return (
        <ApprovalPanel
            docked={docked}
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
