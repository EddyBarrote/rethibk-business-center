import { Link, usePage } from '@inertiajs/react';
import { ChevronDown, LayoutGrid, PanelLeftClose, PanelLeftOpen } from 'lucide-react';
import { type ReactNode, useState } from 'react';

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
 * bar's "‹ Definições" goes back to the grouped list. The large-screen sidebar
 * folds into a column of icons, and the choice is remembered.
 */

const COLLAPSED_KEY = 'settings.nav.collapsed';

export function SettingsHeading({ as: Tag = 'h1', className }: { as?: 'h1' | 'p'; className?: string }) {
    return (
        <div className={cn('flex flex-col gap-1', className)}>
            <Tag className="text-2xl font-semibold tracking-tight">Definições</Tag>
            <p className="text-sm text-muted-foreground">A empresa, as pessoas, a comunicação, os agentes de IA e a sua conta, num só lugar.</p>
        </div>
    );
}

function SettingsNav({
    groups,
    active,
    collapsed,
    onToggle,
}: {
    groups: SettingsGroup[];
    active?: string;
    collapsed: boolean;
    onToggle: () => void;
}) {
    const Toggle = collapsed ? PanelLeftOpen : PanelLeftClose;

    return (
        <nav aria-label="Definições" className={cn('flex flex-col', collapsed ? 'items-center gap-3' : 'gap-4')}>
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={!collapsed}
                aria-label={collapsed ? 'Expandir o menu das definições' : 'Colapsar o menu das definições'}
                title={collapsed ? 'Expandir o menu' : 'Colapsar o menu'}
                className={cn(
                    'inline-flex size-8 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent/60 hover:text-foreground',
                    !collapsed && '-mb-2 self-end',
                )}
            >
                <Toggle className="size-4" />
            </button>
            {groups.map((group) => (
                <div key={group.label} className={cn('flex flex-col gap-0.5', collapsed && 'items-center')}>
                    {collapsed ? (
                        <span aria-hidden className="mb-1 h-px w-5 bg-border" />
                    ) : (
                        <p className="px-2.5 pb-1 text-[10px] font-medium tracking-widest text-muted-foreground/80 uppercase">{group.label}</p>
                    )}
                    {group.items.map((item) => (
                        <Link
                            key={item.href}
                            href={item.href}
                            aria-current={item.href === active ? 'page' : undefined}
                            aria-label={collapsed ? item.label : undefined}
                            title={collapsed ? `${group.label} · ${item.label}` : undefined}
                            className={cn(
                                'flex h-8 items-center gap-2.5 rounded-lg text-sm text-foreground/85 transition-colors hover:bg-accent/60 hover:text-foreground',
                                collapsed ? 'w-8 justify-center' : 'px-2.5',
                                item.href === active && 'bg-accent font-medium text-foreground',
                            )}
                        >
                            <item.icon className={cn('size-4 shrink-0 text-muted-foreground', item.href === active && 'text-foreground')} />
                            {!collapsed && <span className="truncate">{item.label}</span>}
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
                {groups.map((group, index) => (
                    <DropdownMenuGroup key={group.label}>
                        {index > 0 && <DropdownMenuSeparator />}
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
    const [collapsed, setCollapsed] = useState(() => {
        try {
            return localStorage.getItem(COLLAPSED_KEY) === '1';
        } catch {
            return false;
        }
    });
    const toggle = () =>
        setCollapsed((current) => {
            try {
                localStorage.setItem(COLLAPSED_KEY, current ? '0' : '1');
            } catch {
                // Without storage the choice lasts the visit.
            }

            return !current;
        });
    const breadcrumbs: Crumb[] = [
        { label: 'Definições', href: '/settings' },
        ...(section ? [{ label: section.label, href: crumbs.length > 0 ? section.href : undefined }] : []),
        ...crumbs,
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <div className="flex min-w-0 flex-col gap-6">
                <SettingsHeading as="p" className="hidden xl:flex" />
                <div
                    className={cn(
                        'flex min-w-0 flex-col gap-8 xl:grid xl:border-t xl:pt-6',
                        collapsed ? 'xl:grid-cols-[2rem_minmax(0,1fr)] xl:gap-8' : 'xl:grid-cols-[13rem_minmax(0,1fr)] xl:gap-10',
                    )}
                >
                    <div className="hidden xl:sticky xl:top-20 xl:block xl:self-start">
                        <SettingsNav groups={groups} active={section?.href} collapsed={collapsed} onToggle={toggle} />
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
