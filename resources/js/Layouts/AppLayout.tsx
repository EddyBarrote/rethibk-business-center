import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    Puzzle,
    Bell,
    CircleDot,
    MessagesSquare,
    Network,
    Target,
    Bot,
    Building2,
    CheckSquare,
    ChevronLeft,
    ChevronsUpDown,
    FileText,
    Files,
    FolderKanban,
    FolderOpen,
    Inbox,
    LayoutDashboard,
    Library,
    LogOut,
    type LucideIcon,
    Mail,
    Monitor,
    Moon,
    Palette,
    Pencil,
    PlugZap,
    ShieldCheck,
    Sun,
    Users,
} from 'lucide-react';
import { Fragment, type ReactNode, useEffect } from 'react';
import { toast } from 'sonner';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Monogram } from '@/Components/Blocks';
import { RethinkMark } from '@/Components/RethinkMark';
import { StatusDot } from '@/Components/Status';
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/Components/ui/breadcrumb';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
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
    SidebarMenuAction,
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
    tenantManagersOnly?: boolean;
    badge?: 'approvals' | 'notifications' | 'waiting';
}

const topNav: NavItem[] = [
    { label: 'A minha caixa', href: '/', icon: Inbox },
    { label: 'Tarefas', href: '/tasks', icon: CircleDot, badge: 'waiting' },
    { label: 'Emails', href: '/inbox', icon: Mail },
    { label: 'Aprovações', href: '/approvals', icon: CheckSquare, badge: 'approvals' },
    { label: 'Notificações', href: '/notifications', icon: Bell, badge: 'notifications' },
];

const workNav: NavItem[] = [
    { label: 'Conversas', href: '/tasks?view=chats', icon: MessagesSquare },
    { label: 'Painel', href: '/painel', icon: LayoutDashboard },
    { label: 'Objectivos', href: '/goals', icon: Target },
    { label: 'Projectos', href: '/projects', icon: FolderKanban },
    { label: 'Execuções', href: '/runs', icon: Activity },
    { label: 'Briefings', href: '/briefings', icon: FileText },
    { label: 'Documentos', href: '/reports', icon: Files },
    { label: 'Ficheiros', href: '/documents', icon: FolderOpen },
    { label: 'Conhecimento', href: '/knowledge', icon: Library },
];

const companyNav: NavItem[] = [
    { label: 'Organigrama', href: '/org', icon: Network },
    { label: 'Capacidades', href: '/capabilities', icon: Puzzle, tenantManagersOnly: true },
    { label: 'Skills', href: '/skills', icon: BookOpen, tenantManagersOnly: true },
    { label: 'Utilizadores', href: '/settings/users', icon: Users, tenantManagersOnly: true },
    { label: 'Papéis e acessos', href: '/settings/roles', icon: ShieldCheck, tenantManagersOnly: true },
    { label: 'Departamentos', href: '/settings/departments', icon: Building2 },
    { label: 'Marca', href: '/settings/brand', icon: Palette, tenantManagersOnly: true },
    { label: 'Ligação ao ERP', href: '/settings/erp', icon: PlugZap, tenantManagersOnly: true },
];

export interface Crumb {
    label: string;
    href?: string;
}

const appearanceOptions: { value: Appearance; label: string; icon: LucideIcon }[] = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Escuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
];

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

    return (
        <SidebarGroup className="py-1">
            <SidebarGroupLabel className="h-7 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">
                Agentes
                {live > 0 && (
                    <span className="ml-auto font-mono text-[10px] tracking-normal text-status-running normal-case">{live} a trabalhar</span>
                )}
            </SidebarGroupLabel>
            <SidebarMenu className="gap-0.5">
                {agents.map((agent) => (
                    <SidebarMenuItem key={agent.id}>
                        <SidebarMenuButton
                            asChild
                            isActive={agent.chat_id !== null && url === `/tasks/${agent.chat_id}`}
                            tooltip={agent.can_chat ? `Conversar com ${agent.name}` : agent.name}
                            className="h-8 rounded-lg text-sidebar-foreground/85"
                        >
                            {/* Grok-style: an agent in the sidebar is your conversation with it. */}
                            <Link
                                href={
                                    !agent.can_chat
                                        ? `/agents/${agent.id}`
                                        : agent.chat_id !== null
                                          ? `/tasks/${agent.chat_id}`
                                          : `/agents/${agent.id}/chat`
                                }
                            >
                                <AgentAvatar name={agent.name} url={agent.avatar_url} className="size-4 rounded-[5px] text-[8px]" />
                                <span className={cn(agent.status === 'suspended' && 'text-muted-foreground line-through')}>{agent.name}</span>
                                {agent.running > 0 ? (
                                    <StatusDot tone="running" className="ml-auto" />
                                ) : (
                                    agent.chat_waiting && <StatusDot tone="warning" pulse={false} className="ml-auto" />
                                )}
                            </Link>
                        </SidebarMenuButton>
                        {agent.can_manage && (
                            <SidebarMenuAction asChild showOnHover>
                                <Link href={`/agents/${agent.id}/edit`} title={`Editar ${agent.name}`} aria-label={`Editar ${agent.name}`}>
                                    <Pencil />
                                </Link>
                            </SidebarMenuAction>
                        )}
                    </SidebarMenuItem>
                ))}
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
    const { appearance, setAppearance } = useAppearance();
    const { isMobile } = useSidebar();
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
                        <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">Aparência</DropdownMenuLabel>
                        <DropdownMenuRadioGroup value={appearance} onValueChange={(value) => setAppearance(value as Appearance)}>
                            {appearanceOptions.map((option) => (
                                <DropdownMenuRadioItem key={option.value} value={option.value}>
                                    <option.icon className="text-muted-foreground" />
                                    {option.label}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
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

export default function AppLayout({ children, breadcrumbs }: { children: ReactNode; breadcrumbs?: Crumb[] }) {
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
    // A conversation is a task underneath, but people find it under Conversas.
    const onConversation = (page.props as { task?: { is_conversation?: boolean } }).task?.is_conversation === true;
    const isActive = (href: string) => {
        if (href === '/tasks?view=chats') {
            return fullUrl.startsWith(href) || onConversation;
        }
        if (href.includes('?')) {
            return fullUrl.startsWith(href);
        }
        if (href === '/') {
            return url === '/';
        }
        if (href === '/tasks' && (fullUrl.includes('view=chats') || onConversation)) {
            return false;
        }

        return url === href || url.startsWith(`${href}/`);
    };
    const counts = { approvals: auth.pending_approvals, notifications: auth.unread_notifications, waiting: auth.waiting_tasks };
    const allNav = [...topNav, ...workNav, ...companyNav];
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
                        <NavGroup items={topNav} isActive={isActive} counts={counts} />
                        <NavGroup label="Trabalho" items={workNav} isActive={isActive} counts={counts} />
                        <AgentsGroup url={url} />
                        <NavGroup
                            label="Empresa"
                            items={companyNav.filter((item) => !item.tenantManagersOnly || user?.can_manage_tenant)}
                            isActive={isActive}
                            counts={counts}
                        />
                    </SidebarContent>

                    <SidebarFooter className="border-t border-sidebar-border/60 p-2">
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
                                <Link href={parent.href!} className="flex min-w-0 items-center gap-1 text-sm text-muted-foreground hover:text-foreground sm:hidden">
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
                    </header>

                    {/* One width for every page, so content always starts at the same place. */}
                    <main className="mx-auto flex w-full max-w-[90rem] flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8">
                        {children}
                    </main>
                </SidebarInset>
                <Toaster position="bottom-right" />
            </SidebarProvider>
        </TooltipProvider>
    );
}
