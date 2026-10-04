import { Head, router } from '@inertiajs/react';
import { Bell, CheckCheck } from 'lucide-react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Card } from '@/Components/ui/card';
import AppLayout from '@/Layouts/AppLayout';
import { dateTime } from '@/lib/format';
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

export default function NotificationsIndex({ notifications }: { notifications: Paginated<Notice> }) {
    return (
        <AppLayout>
            <Head title="Notificações" />
            <PageHeader
                title="Notificações"
                description="O que os agentes e as vigilâncias lhe quiseram dizer."
                actions={
                    <Button variant="outline" onClick={() => router.post('/notifications/read', {}, { preserveScroll: true })}>
                        <CheckCheck />
                        Marcar todas como lidas
                    </Button>
                }
            />
            {notifications.data.length === 0 ? (
                <EmptyState icon={Bell} title="Sem notificações" description="Quando um agente lhe encaminhar algo ou um prazo se aproximar, aparece aqui." />
            ) : (
                <Card className="divide-y py-0">
                    {notifications.data.map((n) => (
                        <a key={n.id} href={`/notifications/${n.id}`} className={cn('flex gap-3 px-4 py-3 hover:bg-muted/50', !n.read && 'bg-accent/40')}>
                            <span className={cn('mt-1.5 size-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : n.level === 'warning' ? 'bg-amber-500' : 'bg-primary')} />
                            <span className="min-w-0 flex-1">
                                <span className={cn('block text-sm', !n.read && 'font-semibold')}>{n.title}</span>
                                <span className="block text-sm text-muted-foreground">{n.body}</span>
                                <span className="block text-xs text-muted-foreground">
                                    {n.from && `${n.from} · `}
                                    {dateTime(n.created_at)}
                                </span>
                            </span>
                        </a>
                    ))}
                </Card>
            )}
            <Pagination page={notifications} />
        </AppLayout>
    );
}
