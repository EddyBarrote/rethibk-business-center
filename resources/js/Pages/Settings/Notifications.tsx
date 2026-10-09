import { Head, router } from '@inertiajs/react';
import { Bell, Lock, Mail, MonitorSmartphone } from 'lucide-react';
import { useState } from 'react';

import { iconFor } from '@/Components/notifications/meta';
import { PageHeader } from '@/Components/PageHeader';
import { Switch } from '@/Components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { cn } from '@/lib/utils';

type Channel = 'app' | 'toast' | 'email';
type Prefs = Record<Channel, boolean>;

interface Category {
    key: string;
    label: string;
    description: string;
    required: boolean;
    prefs: Prefs;
    defaults: Prefs;
}

const channels: { key: Channel; label: string; hint: string; icon: typeof Bell }[] = [
    { key: 'app', label: 'No centro', hint: 'Fica em Notificações e conta no sino.', icon: Bell },
    { key: 'toast', label: 'Alerta no ecrã', hint: 'Aparece no canto do ecrã no momento em que chega.', icon: MonitorSmartphone },
    { key: 'email', label: 'Por email', hint: 'Também chega à sua caixa de email, com o link para abrir.', icon: Mail },
];

/** A minha conta › Notificações: per kind, where each notification reaches the person. */
export default function NotificationSettings({ categories, email }: { categories: Category[]; email: string }) {
    // Flipped at once; the server keeps the same answer.
    const [prefs, setPrefs] = useState<Record<string, Prefs>>(() => Object.fromEntries(categories.map((c) => [c.key, c.prefs])));

    const flip = (category: string, channel: Channel, enabled: boolean) => {
        setPrefs((all) => {
            return { ...all, [category]: { ...all[category], [channel]: enabled } };
        });
        router.put('/settings/notifications', { category, channel, enabled }, { preserveScroll: true, preserveState: true });
    };

    return (
        <SettingsLayout>
            <Head title="Notificações" />
            <PageHeader
                title="Notificações"
                description={
                    <>
                        Escolha, para cada tipo, onde lhe chega. Os emails vão para <span className="font-mono text-xs text-foreground">{email}</span>.
                    </>
                }
            />

            <div className="overflow-hidden rounded-xl border bg-card">
                <div className="hidden grid-cols-[minmax(0,1fr)_repeat(3,8rem)] items-end gap-4 border-b bg-muted/30 px-5 py-3 md:grid">
                    <span className="text-xs font-medium text-muted-foreground">Tipo</span>
                    {channels.map((channel) => (
                        <Tooltip key={channel.key}>
                            <TooltipTrigger asChild>
                                <span className="flex cursor-default items-center justify-center gap-1.5 text-xs font-medium text-muted-foreground">
                                    <channel.icon className="size-3.5" />
                                    {channel.label}
                                </span>
                            </TooltipTrigger>
                            <TooltipContent>{channel.hint}</TooltipContent>
                        </Tooltip>
                    ))}
                </div>
                <ul className="divide-y">
                    {categories.map((category) => {
                        const Icon = iconFor(category.key);
                        const current = prefs[category.key];

                        return (
                            <li key={category.key} className="grid gap-3 px-5 py-4 md:grid-cols-[minmax(0,1fr)_repeat(3,8rem)] md:items-center md:gap-4">
                                <div className="flex items-start gap-3">
                                    <span
                                        className={cn(
                                            'mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-lg',
                                            category.required ? 'bg-status-warning/15 text-foreground' : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        <Icon className="size-4" />
                                    </span>
                                    <div className="min-w-0">
                                        <div className="text-sm font-medium">{category.label}</div>
                                        <p className="text-sm text-muted-foreground">{category.description}</p>
                                    </div>
                                </div>
                                {channels.map((channel) => {
                                    const locked = category.required && channel.key === 'app';
                                    const off = channel.key === 'toast' && !current.app;
                                    const id = `pref-${category.key}-${channel.key}`;

                                    return (
                                        <label
                                            key={channel.key}
                                            htmlFor={id}
                                            className="flex items-center justify-between gap-3 text-sm text-muted-foreground max-md:pl-11 md:justify-center"
                                        >
                                            <span className="md:sr-only">{channel.label}</span>
                                            {locked ? (
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span className="inline-flex items-center gap-1.5">
                                                            <Lock className="size-3.5" />
                                                            <Switch id={id} checked disabled />
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>Sempre ligado: só uma pessoa pode decidir uma aprovação.</TooltipContent>
                                                </Tooltip>
                                            ) : (
                                                <Switch
                                                    id={id}
                                                    checked={current[channel.key] && !off}
                                                    disabled={off}
                                                    onCheckedChange={(on) => flip(category.key, channel.key, on)}
                                                />
                                            )}
                                        </label>
                                    );
                                })}
                            </li>
                        );
                    })}
                </ul>
            </div>
            <p className="text-xs text-muted-foreground">
                O alerta no ecrã só aparece para o que fica no centro. Desligar «No centro» deixa de guardar esse tipo, mas o email, se ligado, continua a chegar.
            </p>
        </SettingsLayout>
    );
}
