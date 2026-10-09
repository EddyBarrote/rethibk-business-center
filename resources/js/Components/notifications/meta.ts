import { Bell, Bot, CheckSquare, CircleDot, Clock, FileText, Hourglass, type LucideIcon, Mail, MessagesSquare } from 'lucide-react';

import type { Tone } from '@/Components/Status';

/** One notification as the centre and the bell show it (NotificationController::present). */
export interface Notice {
    id: string;
    category: string;
    title: string;
    body: string;
    url: string | null;
    from: string | null;
    level: string;
    read: boolean;
    created_at: string;
    approval: {
        id: number;
        status: string;
        status_label: string;
        can_decide: boolean;
        decided_by: string | null;
    } | null;
}

/** What the server broadcasts on the person's channel (Notifier::notify). */
export interface LiveNotice {
    id: string;
    category: string;
    title: string;
    body: string;
    url: string | null;
    from: string | null;
    level: string;
    toast: boolean;
}

export const categoryIcon: Record<string, LucideIcon> = {
    approval: CheckSquare,
    waiting: Hourglass,
    task: CircleDot,
    conversation: MessagesSquare,
    email: Mail,
    deadline: Clock,
    document: FileText,
    agent: Bot,
};

export const iconFor = (category: string): LucideIcon => categoryIcon[category] ?? Bell;

export const levelTone = (level: string): Tone =>
    (({ warning: 'warning', danger: 'danger', error: 'danger', success: 'success' })[level] as Tone) ?? 'running';

/** "Hoje", "Ontem", or the day itself: the centre groups by day. */
export function dayLabel(value: string): string {
    const day = new Date(value);
    const today = new Date();
    const start = (d: Date) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();
    const diff = Math.round((start(today) - start(day)) / 86_400_000);

    if (diff === 0) {
        return 'Hoje';
    }
    if (diff === 1) {
        return 'Ontem';
    }

    const label = new Intl.DateTimeFormat('pt-PT', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        ...(day.getFullYear() !== today.getFullYear() ? { year: 'numeric' } : {}),
    }).format(day);

    return label.charAt(0).toUpperCase() + label.slice(1);
}

export const dayKey = (value: string) => {
    const d = new Date(value);

    return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
};
