import { runTone, StatusBadge } from '@/Components/Status';
import type { RunStatus } from '@/types';

export function RunStatusBadge({ status, label }: { status: RunStatus; label: string }) {
    return <StatusBadge tone={runTone(status)}>{label}</StatusBadge>;
}
