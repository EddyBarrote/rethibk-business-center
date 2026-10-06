import { Head, router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { ListPanel, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusDot, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime, plainText } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

interface Notice {
    id: string;
    title: string;
    body: string;
    url: string | null;
    from: string | null;
    level: string;
    read: boolean;
    created_at: string;
}

const levelTone = (level: string): Tone =>
    (({ warning: 'warning', danger: 'danger', error: 'danger', success: 'success' })[level] as Tone) ?? 'running';

export default function NotificationsIndex({ notifications }: { notifications: Paginated<Notice> }) {
    const unread = notifications.data.filter((notice) => !notice.read).length;

    return (
        <AppLayout>
            <Head title="Notificações" />
            <PageHeader
                title="Notificações"
                description="O que os agentes e as vigilâncias lhe quiseram dizer."
                actions={
                    <Button variant="outline" size="sm" onClick={() => router.post('/notifications/read', {}, { preserveScroll: true })}>
                        <CheckCheck />
                        Marcar todas como lidas
                    </Button>
                }
            />

            {notifications.data.length === 0 ? (
                <EmptyState
                    icon={Bell}
                    title="Sem notificações"
                    description="Quando um agente lhe encaminhar algo ou um prazo se aproximar, aparece aqui."
                />
            ) : (
                <Section
                    title="Caixa"
                    action={
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {unread > 0 ? `${unread} por ler nesta página` : 'Tudo lido'}
                        </span>
                    }
                >
                    <ListPanel>
                        {notifications.data.map((notice) => (
                            <a
                                key={notice.id}
                                href={`/notifications/${notice.id}`}
                                className={cn(
                                    'flex items-start gap-3 px-4 py-3 transition-colors hover:bg-accent/60',
                                    !notice.read && 'bg-accent/30',
                                )}
                            >
                                <span className="flex w-2 shrink-0 justify-center pt-3">
                                    {!notice.read && <StatusDot tone={levelTone(notice.level)} pulse={false} />}
                                </span>
                                {notice.from ? (
                                    <AgentAvatar name={notice.from} />
                                ) : (
                                    <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <Bell className="size-3.5" />
                                    </span>
                                )}
                                <span className="min-w-0 flex-1">
                                    <span
                                        className={cn('block truncate text-sm', notice.read ? 'font-medium text-muted-foreground' : 'font-semibold')}
                                    >
                                        {notice.title}
                                    </span>
                                    <span className="line-clamp-2 text-sm text-muted-foreground">{plainText(notice.body)}</span>
                                    {notice.from && <span className="block text-xs text-muted-foreground">{notice.from}</span>}
                                </span>
                                <span className="shrink-0 pt-0.5 text-xs text-muted-foreground" title={dateTime(notice.created_at)}>
                                    {ago(notice.created_at)}
                                </span>
                            </a>
                        ))}
                    </ListPanel>
                </Section>
            )}
            <Pagination page={notifications} noun={['notificação', 'notificações']} />
        </AppLayout>
    );
}
