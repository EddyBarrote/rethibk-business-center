import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, LayoutGrid } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/Components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import AppLayout, { type Crumb } from '@/Layouts/AppLayout';
import { type SettingsGroup, type SettingsItem, settingsSection, visibleSettings } from '@/lib/settings';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/*
 * Definições: the settings get their own grouped sidebar next to the page
 * (docs/UI.md, pattern 28). On a large screen the sidebar sits on the left;
 * on a tablet a menu above the page switches section; on a phone the top
 * bar's "‹ Definições" goes back to the grouped list.
 */

export function SettingsHeading({ as: Tag = 'h1', className }: { as?: 'h1' | 'p'; className?: string }) {
    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <Tag className="text-2xl font-semibold tracking-tight">Definições</Tag>
            <p className="text-sm text-muted-foreground">A empresa, as pessoas, a comunicação, os agentes de IA e a sua conta, num só lugar.</p>
        </div>
    );
}

function SettingsNav({ groups, active }: { groups: SettingsGroup[]; active?: string }) {
    return (
        <nav aria-label="Definições" className="flex flex-col gap-4">
            {groups.map((group) => (
                <div key={group.label} className="flex flex-col gap-0.5">
                    <p className="px-2.5 pb-1 text-[10px] font-medium tracking-widest text-muted-foreground/80 uppercase">{group.label}</p>
                    {group.items.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={item.href === active ? 'page' : undefined}
                            className={cn(
                                'flex h-8 items-center gap-2.5 rounded-lg px-2.5 text-sm text-foreground/85 transition-colors hover:bg-accent/60 hover:text-foreground',
                                item.href === active && 'bg-accent font-medium text-foreground',
                            )}
                        >
                            <item.icon className="size-4 shrink-0 text-muted-foreground" />
                            <span className="truncate">{item.label}</span>
                        </Link>
                    ))}
                </div>
            ))}
        </nav>
    );
}

function SectionMenu({ groups, section }: { groups: SettingsGroup[]; section?: SettingsItem }) {
    const Icon = section?.icon ?? LayoutGrid;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" className="w-fit">
                    <Icon className="text-muted-foreground" />
                    {section?.label ?? 'Definições'}
                    <ChevronDown className="text-muted-foreground" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" className="w-64">
                <DropdownMenuItem asChild>
                    <Link href="/settings">
                        <LayoutGrid />
                        Todas as definições
                    </Link>
                </DropdownMenuItem>
                {groups.map((group) => (
                    <DropdownMenuGroup key={group.label}>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">{group.label}</DropdownMenuLabel>
                        {group.items.map((item) => (
                            <DropdownMenuItem key={item.href} asChild className={cn(item.href === section?.href && 'bg-accent font-medium')}>
                                <Link href={item.href}>
                                    <item.icon />
                                    {item.label}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuGroup>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** A settings page: the trail starts at Definições and the section, then whatever the page adds. */
export default function SettingsLayout({ children, crumbs = [] }: { children: ReactNode; crumbs?: Crumb[] }) {
    const page = usePage<SharedProps>();
    const groups = visibleSettings(page.props.auth.user?.permissions);
    const section = settingsSection(page.url);
    const breadcrumbs: Crumb[] = [
        { label: 'Definições', href: '/settings' },
        ...(section ? [{ label: section.label, href: crumbs.length > 0 ? section.href : undefined }] : []),
        ...crumbs,
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex min-w-0 flex-col gap-6">
                <SettingsHeading as="p" className="hidden xl:flex" />
                <div className="flex min-w-0 flex-col gap-8 xl:grid xl:grid-cols-[13rem_minmax(0,1fr)] xl:gap-10 xl:border-t xl:pt-6">
                    <div className="hidden xl:sticky xl:top-20 xl:block xl:self-start">
                        <SettingsNav groups={groups} active={section?.href} />
                    </div>
                    <div className="flex min-w-0 flex-col gap-8">
                        <div className="hidden sm:block xl:hidden">
                            <SectionMenu groups={groups} section={section} />
                        </div>
                        {children}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
