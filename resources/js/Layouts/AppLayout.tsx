import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    CircleDot,
    Network,
    Target,
    Bot,
    CheckSquare,
    ChevronLeft,
    ChevronsUpDown,
    FileText,
    Files,
    FolderKanban,
    FolderOpen,
    LayoutDashboard,
    Library,
    LogOut,
    type LucideIcon,
    Mail,
    Moon,
    Settings,
    Sun,
    UserRound,
    Workflow,
} from 'lucide-react';
import { Fragment, type ReactNode, useEffect } from 'react';
import { toast } from 'sonner';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Monogram } from '@/Components/Blocks';
import { NotificationBell } from '@/Components/notifications/NotificationBell';
import { RethinkMark } from '@/Components/RethinkMark';
import { StatusDot } from '@/Components/Status';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/Components/ui/breadcrumb';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Separator } from '@/Components/ui/separator';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarInset,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarProvider,
    SidebarRail,
    SidebarTrigger,
    useSidebar,
} from '@/Components/ui/sidebar';
import { Toaster } from '@/Components/ui/sonner';
import { TooltipProvider } from '@/Components/ui/tooltip';
import { type Appearance, useAppearance } from '@/lib/appearance';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

/*
 * Operator-console shell modelled on Paperclip (MIT): a dense sectioned sidebar
 * with live agent status, a breadcrumb top bar, and content that answers
 * "what is happening, does it need me, what do I do about it".
 */

interface NavItem {
    label: string;
    href: string;
    icon: LucideIcon;
    /** Shown only to people with one of these permissions (the server checks them too). */
    permissions?: string[];
    badge?: 'approvals' | 'notifications' | 'waiting';
}

// Coming in opens the person's latest conversation: the agents below are the conversations (DECISOES, "Conversas a partir dos agentes").
const topNav: NavItem[] = [
    { label: 'Tarefas', href: '/tasks', icon: CircleDot, badge: 'waiting' },
    { label: 'Email', href: '/inbox', icon: Mail, permissions: ['emails.own_mailboxes', 'emails.triage', 'emails.manage_mailboxes'] },
    { label: 'Aprovações', href: '/approvals', icon: CheckSquare, badge: 'approvals' },
    { label: 'Notificações', href: '/notifications', icon: Bell, badge: 'notifications' },
];

const workNav: NavItem[] = [
    { label: 'Painel', href: '/painel', icon: LayoutDashboard },
    { label: 'Objectivos', href: '/goals', icon: Target },
    { label: 'Projectos', href: '/projects', icon: FolderKanban },
    { label: 'Fluxos de trabalho', href: '/workflows', icon: Workflow },
    { label: 'Execuções', href: '/runs', icon: Activity },
    { label: 'Briefings', href: '/briefings', icon: FileText },
    { label: 'Documentos', href: '/reports', icon: Files },
    { label: 'Ficheiros', href: '/documents', icon: FolderOpen },
    { label: 'Conhecimento', href: '/knowledge', icon: Library },
    { label: 'Organigrama', href: '/org', icon: Network },
];

// Everything else that is configured lives in Definições, with its own grouped sidebar (lib/settings.ts).
const settingsNav: NavItem = { label: 'Definições', href: '/settings', icon: Settings };

export interface Crumb {
    label: string;
    href?: string;
}

const appearanceLabel: Record<Appearance, string> = { light: 'Claro', dark: 'Escuro', system: 'Sistema' };

function sidebarCookieOpen() {
    if (typeof document === 'undefined') {
        return true;
    }

    return !document.cookie.split('; ').includes('sidebar_state=false');
}

function NavGroup({
    label,
    items,
    isActive,
    counts,
}: {
    label?: string;
    items: NavItem[];
    isActive: (href: string) => boolean;
    counts: Record<string, number>;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="py-1">
            {label && (
                <SidebarGroupLabel className="h-7 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">
                    {label}
                </SidebarGroupLabel>
            )}
            <SidebarMenu className="gap-0.5">
                {items.map((item) => {
                    const count = item.badge ? (counts[item.badge] ?? 0) : 0;

                    return (
                        <SidebarMenuItem key={item.href}>
                            <SidebarMenuButton
                                asChild
                                isActive={isActive(item.href)}
                                tooltip={item.label}
                                className="h-8 rounded-lg font-medium text-sidebar-foreground/85"
                            >
                                <Link href={item.href}>
                                    <item.icon />
                                    <span>{item.label}</span>
                                </Link>
                            </SidebarMenuButton>
                            {count > 0 && (
                                <SidebarMenuBadge
                                    className={cn(
                                        'rounded-full px-1.5 font-mono text-[11px]',
                                        item.badge === 'approvals' || item.badge === 'waiting'
                                            ? 'bg-status-warning/20 text-foreground'
                                            : 'bg-primary text-primary-foreground',
                                    )}
                                >
                                    {count > 99 ? '99+' : count}
                                </SidebarMenuBadge>
                            )}
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}

function AgentsGroup({ url }: { url: string }) {
    const { sidebar_agents: agents } = usePage<SharedProps>().props;
    const live = agents.filter((agent) => agent.running > 0).length;
    // The agents are the conversations: talked-to first, the most recent on top (the server orders them).
    const talked = agents.filter((agent) => agent.chat_id !== null);
    const others = agents.filter((agent) => agent.chat_id === null);

    const item = (agent: SharedProps['sidebar_agents'][number]) => {
        const href = !agent.can_chat ? `/agents/${agent.id}` : agent.chat_id !== null ? `/tasks/${agent.chat_id}` : `/agents/${agent.id}/chat`;
        const active = (agent.chat_id !== null && url === `/tasks/${agent.chat_id}`) || url === `/agents/${agent.id}/chat`;
        const hint = [
            agent.can_chat ? `Conversar com ${agent.name}` : agent.name,
            agent.running > 0 ? 'a trabalhar' : null,
            agent.chat_waiting ? 'à sua espera' : null,
            agent.unread > 0 ? `${agent.unread} por ler` : null,
        ]
            .filter(Boolean)
            .join(' · ');

        return (
            <SidebarMenuItem key={agent.id}>
                <SidebarMenuButton
                    asChild
                    isActive={active}
                    tooltip={hint}
                    className={cn('h-8 rounded-lg text-sidebar-foreground/85', agent.chat_id === null && 'text-muted-foreground')}
                >
                    <Link href={href} title={hint}>
                        <span className="relative shrink-0">
                            <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-4 rounded-[5px] text-[8px]" />
                            {/* Collapsed to icons, the dot still says it needs you. */}
                            {(agent.unread > 0 || agent.chat_waiting) && (
                                <span className="absolute -top-1 -right-1 hidden size-2 rounded-full bg-primary ring-2 ring-sidebar group-data-[collapsible=icon]:block" />
                            )}
                        </span>
                        <span
                            className={cn(
                                'truncate',
                                agent.unread > 0 && 'font-semibold text-sidebar-foreground',
                                agent.status === 'suspended' && 'text-muted-foreground line-through',
                            )}
                        >
                            {agent.name}
                        </span>
                        <span className="ml-auto flex items-center gap-1.5">
                            {agent.running > 0 ? <StatusDot tone="running" /> : agent.chat_waiting && <StatusDot tone="warning" pulse={false} />}
                            {agent.unread > 0 && (
                                <span className="rounded-full bg-primary px-1.5 font-mono text-[10px] leading-4 text-primary-foreground tabular-nums">
                                    {agent.unread > 99 ? '99+' : agent.unread}
                                </span>
                            )}
                        </span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        );
    };

    return (
        <SidebarGroup className="py-1">
            <SidebarGroupLabel className="h-7 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">
                Agentes
                {live > 0 && (
                    <span className="ml-auto font-mono text-[10px] tracking-normal text-status-running normal-case">{live} a trabalhar</span>
                )}
            </SidebarGroupLabel>
            <SidebarMenu className="gap-0.5">
                {talked.map(item)}
                {others.length > 0 && talked.length > 0 && (
                    <li className="px-2 pt-2 pb-0.5 text-[10px] text-muted-foreground/70 group-data-[collapsible=icon]:hidden">
                        Começar uma conversa
                    </li>
                )}
                {others.map(item)}
                <SidebarMenuItem>
                    <SidebarMenuButton
                        asChild
                        isActive={url === '/agents'}
                        tooltip="Ver todos os agentes"
                        className="h-8 rounded-lg text-muted-foreground"
                    >
                        <Link href="/agents">
                            <Bot />
                            <span>Ver todos</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarGroup>
    );
}

function UserMenu() {
    const { auth } = usePage<SharedProps>().props;
    const { appearance, resolved } = useAppearance();
    const { isMobile } = useSidebar();
    const AppearanceIcon = resolved === 'dark' ? Moon : Sun;
    const user = auth.user;

    if (!user) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton size="lg" className="rounded-lg data-[state=open]:bg-sidebar-accent">
                            <Monogram name={user.name} className="size-8" />
                            <div className="grid flex-1 text-left leading-tight">
                                <span className="truncate text-sm font-medium">{user.name}</span>
                                <span className="truncate text-xs text-muted-foreground">{user.role_label}</span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side={isMobile ? 'bottom' : 'right'} align="end" className="w-60">
                        <DropdownMenuLabel className="font-normal">
                            <p className="text-sm font-medium">{user.name}</p>
                            <p className="text-xs text-muted-foreground">{user.email}</p>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem asChild>
                            <Link href="/settings/profile">
                                <UserRound />
                                Perfil
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/settings/appearance">
                                <AppearanceIcon />
                                Aparência
                                <span className="ml-auto text-xs text-muted-foreground">{appearanceLabel[appearance]}</span>
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem asChild>
                            <Link href="/settings">
                                <Settings />
                                Definições
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => router.post('/logout')}>
                            <LogOut />
                            Terminar sessão
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

export default function AppLayout({
    children,
    breadcrumbs,
    bleed = false,
}: {
    children: ReactNode;
    breadcrumbs?: Crumb[];
    /** The page fills the space under the top bar edge to edge and scrolls its own parts (a conversation with its side panel). */
    bleed?: boolean;
}) {
    const page = usePage<SharedProps>();
    const { auth, tenant, flash } = page.props;
    const url = page.url.split('?')[0];
    const user = auth.user;

    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        }
        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash.success, flash.error]);

    const fullUrl = page.url;
    // A conversation is a task underneath, but people find it under its agent.
    const onConversation = (page.props as { task?: { is_conversation?: boolean } }).task?.is_conversation === true;
    const isActive = (href: string) => {
        if (href.includes('?')) {
            return fullUrl.startsWith(href);
        }
        if (href === '/tasks' && onConversation) {
            return false;
        }

        return url === href || url.startsWith(`${href}/`);
    };
    const allowed = (item: NavItem) => !item.permissions || item.permissions.some((p) => user?.permissions.includes(p));
    const counts = { approvals: auth.pending_approvals, notifications: auth.unread_notifications, waiting: auth.waiting_tasks };
    const allNav = [...topNav, ...workNav, settingsNav];
    const current = allNav.filter((item) => isActive(item.href)).sort((a, b) => b.href.length - a.href.length)[0];
    const trail: Crumb[] = breadcrumbs ?? (current ? [{ label: current.label }] : url.startsWith('/agents') ? [{ label: 'Agentes' }] : []);

    return (
        <TooltipProvider delayDuration={300}>
            <SidebarProvider defaultOpen={sidebarCookieOpen()}>
                <Sidebar collapsible="icon" variant="sidebar">
                    <SidebarHeader className="px-3 pt-3 pb-1">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton size="lg" asChild className="rounded-lg">
                                    <Link href="/">
                                        <RethinkMark className="size-8" />
                                        <div className="grid flex-1 text-left leading-tight">
                                            <span className="truncate text-sm font-semibold">{tenant?.name ?? 'Plataforma'}</span>
                                            <span className="truncate text-xs text-muted-foreground">Plataforma de Agentes</span>
                                        </div>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarHeader>

                    <SidebarContent className="gap-1 px-1">
                        <NavGroup items={topNav.filter(allowed)} isActive={isActive} counts={counts} />
                        <NavGroup label="Trabalho" items={workNav.filter(allowed)} isActive={isActive} counts={counts} />
                        <AgentsGroup url={url} />
                    </SidebarContent>

                    <SidebarFooter className="gap-1 border-t border-sidebar-border/60 p-2">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton
                                    asChild
                                    isActive={isActive(settingsNav.href)}
                                    tooltip={settingsNav.label}
                                    className="h-8 rounded-lg font-medium text-sidebar-foreground/85"
                                >
                                    <Link href={settingsNav.href}>
                                        <settingsNav.icon />
                                        <span>{settingsNav.label}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                        <UserMenu />
                    </SidebarFooter>
                    <SidebarRail />
                </Sidebar>

                <SidebarInset className="min-w-0">
                    <header className="sticky top-0 z-30 flex h-12 shrink-0 items-center gap-2 border-b bg-background/85 px-4 backdrop-blur">
                        <SidebarTrigger className="-ml-1 text-muted-foreground" />
                        <Separator orientation="vertical" className="mr-1 data-[orientation=vertical]:h-4" />
                        {/* On a phone the trail is one way back: "‹ Tarefas", or the page's name at the top level. */}
                        {(() => {
                            const parent = [...trail.slice(0, -1)].reverse().find((crumb) => crumb.href);

                            return parent ? (
                                <Link
                                    href={parent.href!}
                                    className="flex min-w-0 items-center gap-1 text-sm text-muted-foreground hover:text-foreground sm:hidden"
                                >
                                    <ChevronLeft className="size-4 shrink-0" />
                                    <span className="truncate">{parent.label}</span>
                                </Link>
                            ) : (
                                <span className="truncate text-sm font-medium sm:hidden">{trail[trail.length - 1]?.label}</span>
                            );
                        })()}
                        <Breadcrumb className="hidden min-w-0 sm:block">
                            <BreadcrumbList className="flex-nowrap">
                                {trail.map((crumb, index) => {
                                    const last = index === trail.length - 1;

                                    return (
                                        <Fragment key={index}>
                                            {index > 0 && <BreadcrumbSeparator />}
                                            <BreadcrumbItem className={cn('min-w-0', !last && 'hidden sm:inline-flex')}>
                                                {last || !crumb.href ? (
                                                    <BreadcrumbPage className="truncate font-medium">{crumb.label}</BreadcrumbPage>
                                                ) : (
                                                    <BreadcrumbLink asChild>
                                                        <Link href={crumb.href}>{crumb.label}</Link>
                                                    </BreadcrumbLink>
                                                )}
                                            </BreadcrumbItem>
                                        </Fragment>
                                    );
                                })}
                            </BreadcrumbList>
                        </Breadcrumb>
                        <div className="ml-auto flex items-center gap-1">
                            <NotificationBell />
                        </div>
                    </header>

                    {/* One width for every page, so content always starts at the same place. */}
                    {bleed ? (
                        <main className="flex h-[calc(100svh-3rem)] min-h-0 w-full overflow-hidden">{children}</main>
                    ) : (
                        <main className="mx-auto flex w-full max-w-[90rem] flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8">{children}</main>
                    )}
                </SidebarInset>
                <Toaster position="bottom-right" />
            </SidebarProvider>
        </TooltipProvider>
    );
}
