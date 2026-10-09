import { Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, Loader2, Settings2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

import { StatusDot } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { useLive } from '@/hooks/useLive';
import { ago, dateTime, plainText } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

import { iconFor, levelTone, type LiveNotice, type Notice } from './meta';

/**
 * The bell in the top bar: how many are unread, the latest few, and the way
 * into the centre. New ones arrive live and, for the kinds the person chose,
 * pop up as an alert they can open.
 */
export function NotificationBell() {
    const { auth, tenant } = usePage<SharedProps>().props;
    const unread = auth.unread_notifications;
    const [open, setOpen] = useState(false);
    const [items, setItems] = useState<Notice[] | null>(null);
    const [loading, setLoading] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        fetch('/notifications/recent', { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(response)))
            .then((data: { notifications: Notice[] }) => setItems(data.notifications))
            .catch(() => setItems((current) => current ?? []))
            .finally(() => setLoading(false));
    }, []);

    useEffect(() => {
        if (open) {
            load();
        }
    }, [open, load]);

    useLive<LiveNotice>(tenant && auth.user ? `tenant.${tenant.id}.user.${auth.user.id}` : null, ['UserNotified'], (_event, notice) => {
        router.reload({ only: ['auth'] });
        if (open) {
            load();
        }
        if (notice.toast) {
            toast(notice.title, {
                description: plainText(notice.body).slice(0, 140) || undefined,
                action: { label: 'Abrir', onClick: () => router.visit(`/notifications/${notice.id}`) },
            });
        }
    });

    const readAll = () =>
        router.post(
            '/notifications/read',
            {},
            {
                preserveScroll: true,
                onSuccess: () => setItems((current) => current?.map((n) => ({ ...n, read: true })) ?? null),
            },
        );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-8 text-muted-foreground hover:text-foreground"
                    aria-label={unread > 0 ? `Notificações, ${unread} por ler` : 'Notificações'}
                >
                    <Bell className="size-4" />
                    {unread > 0 && (
                        <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-primary px-1 font-mono text-[10px] leading-none font-medium text-primary-foreground tabular-nums">
                            {unread > 99 ? '99+' : unread}
                        </span>
                    )}
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" sideOffset={8} className="w-[min(24rem,calc(100vw-1.5rem))] p-0">
                <div className="flex items-center gap-2 border-b px-4 py-2.5">
                    <span className="text-sm font-semibold">Notificações</span>
                    {unread > 0 && <span className="text-xs text-muted-foreground tabular-nums">{unread} por ler</span>}
                    <Button variant="ghost" size="xs" className="ml-auto text-muted-foreground" disabled={unread === 0} onClick={readAll}>
                        <CheckCheck />
                        Marcar todas como lidas
                    </Button>
                </div>

                <div className="max-h-[min(28rem,calc(100dvh-10rem))] overflow-y-auto">
                    {items === null ? (
                        <div className="flex items-center justify-center py-10 text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" />
                        </div>
                    ) : items.length === 0 ? (
                        <div className="flex flex-col items-center gap-1 px-6 py-10 text-center">
                            <Bell className="size-5 text-muted-foreground" />
                            <span className="text-sm font-medium">Sem notificações</span>
                            <span className="text-xs text-muted-foreground">Quando um agente precisar de si, aparece aqui.</span>
                        </div>
                    ) : (
                        <ul className={cn('divide-y transition-opacity', loading && 'opacity-70')}>
                            {items.map((notice) => {
                                const Icon = iconFor(notice.category);
                                const decide = notice.approval?.can_decide;

                                return (
                                    <li key={notice.id}>
                                        <Link
                                            href={decide ? '/notifications?tipo=approval' : `/notifications/${notice.id}`}
                                            onClick={() => setOpen(false)}
                                            className={cn('flex items-start gap-3 px-4 py-2.5 transition-colors hover:bg-accent/60', !notice.read && 'bg-primary/[0.04]')}
                                        >
                                            <span
                                                className={cn(
                                                    'mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-lg',
                                                    notice.category === 'approval' ? 'bg-status-warning/15 text-foreground' : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                <Icon className="size-3.5" />
                                            </span>
                                            <span className="min-w-0 flex-1">
                                                <span className={cn('line-clamp-1 text-sm', notice.read ? 'text-muted-foreground' : 'font-medium')}>{notice.title}</span>
                                                {notice.body && <span className="line-clamp-1 text-xs text-muted-foreground">{plainText(notice.body)}</span>}
                                                <span className="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                                    <span title={dateTime(notice.created_at)}>{ago(notice.created_at)}</span>
                                                    {notice.from && <span className="truncate">· {notice.from}</span>}
                                                    {decide && <span className="font-medium text-foreground">· Decidir</span>}
                                                </span>
                                            </span>
                                            {!notice.read && <StatusDot tone={levelTone(notice.level)} pulse={false} className="mt-2" />}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </div>

                <div className="flex items-center gap-2 border-t px-2 py-1.5">
                    <Button variant="ghost" size="sm" className="flex-1 justify-center" asChild>
                        <Link href="/notifications" onClick={() => setOpen(false)}>
                            Ver todas as notificações
                        </Link>
                    </Button>
                    <Button variant="ghost" size="icon" className="size-8 text-muted-foreground" asChild>
                        <Link href="/settings/notifications" onClick={() => setOpen(false)} aria-label="Preferências de notificações">
                            <Settings2 className="size-4" />
                        </Link>
                    </Button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
