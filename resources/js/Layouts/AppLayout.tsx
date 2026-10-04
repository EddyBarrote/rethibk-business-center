import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Bell,
    Bot,
    Briefcase,
    Building2,
    CheckSquare,
    ChevronsUpDown,
    FileSignature,
    FileText,
    Files,
    Gavel,
    Inbox,
    LayoutDashboard,
    Landmark,
    Library,
    LogOut,
    type LucideIcon,
    Monitor,
    Moon,
    PlugZap,
    ShoppingCart,
    Sun,
    Users,
} from 'lucide-react';
import { Fragment, type ReactNode, useEffect } from 'react';
import { toast } from 'sonner';

import { Monogram } from '@/Components/Blocks';
import { RethinkMark } from '@/Components/RethinkMark';
import { StatusDot } from '@/Components/Status';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/Components/ui/breadcrumb';
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
    managersOnly?: boolean;
    tenantManagersOnly?: boolean;
    badge?: 'approvals' | 'notifications';
}

const topNav: NavItem[] = [
    { label: 'Painel', href: '/', icon: LayoutDashboard },
    { label: 'Caixa de entrada', href: '/inbox', icon: Inbox },
    { label: 'Aprovações', href: '/approvals', icon: CheckSquare, badge: 'approvals' },
    { label: 'Notificações', href: '/notifications', icon: Bell, badge: 'notifications' },
];

const workNav: NavItem[] = [
    { label: 'Execuções', href: '/runs', icon: Activity },
    { label: 'Briefings', href: '/briefings', icon: FileText },
    { label: 'Documentos', href: '/reports', icon: Files },
    { label: 'Memória', href: '/knowledge', icon: Library },
];

const areasNav: NavItem[] = [
    { label: 'Concursos', href: '/tenders', icon: Gavel },
    { label: 'Clientes', href: '/clients', icon: Briefcase, managersOnly: true },
    { label: 'Finanças', href: '/finance', icon: Landmark, managersOnly: true },
    { label: 'Compras', href: '/procurement', icon: ShoppingCart },
    { label: 'Contratos', href: '/contracts', icon: FileSignature, managersOnly: true },
];

const companyNav: NavItem[] = [
    { label: 'Utilizadores', href: '/settings/users', icon: Users, tenantManagersOnly: true },
    { label: 'Departamentos', href: '/settings/departments', icon: Building2 },
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

function NavGroup({ label, items, isActive, counts }: { label?: string; items: NavItem[]; isActive: (href: string) => boolean; counts: Record<string, number> }) {
    if (items.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="py-1">
            {label && <SidebarGroupLabel className="h-7 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">{label}</SidebarGroupLabel>}
            <SidebarMenu className="gap-0.5">
                {items.map((item) => {
                    const count = item.badge ? (counts[item.badge] ?? 0) : 0;

                    return (
                        <SidebarMenuItem key={item.href}>
                            <SidebarMenuButton asChild isActive={isActive(item.href)} tooltip={item.label} className="h-8 rounded-lg font-medium text-sidebar-foreground/85">
                                <Link href={item.href}>
                                    <item.icon />
                                    <span>{item.label}</span>
                                </Link>
                            </SidebarMenuButton>
                            {count > 0 && (
                                <SidebarMenuBadge
                                    className={cn(
                                        'rounded-full px-1.5 font-mono text-[11px]',
                                        item.badge === 'approvals' ? 'bg-status-warning/20 text-foreground' : 'bg-primary text-primary-foreground',
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
                {live > 0 && <span className="ml-auto font-mono text-[10px] tracking-normal text-status-running normal-case">{live} a trabalhar</span>}
            </SidebarGroupLabel>
            <SidebarMenu className="gap-0.5">
                {agents.map((agent) => (
                    <SidebarMenuItem key={agent.id}>
                        <SidebarMenuButton asChild isActive={url.startsWith(`/agents/${agent.id}`)} tooltip={agent.name} className="h-8 rounded-lg text-sidebar-foreground/85">
                            <Link href={`/agents/${agent.id}`}>
                                <Monogram name={agent.name} agent className="size-4 rounded-[5px] text-[8px]" />
                                <span className={cn(agent.status === 'suspended' && 'text-muted-foreground line-through')}>{agent.name}</span>
                                {agent.running > 0 && <StatusDot tone="running" className="ml-auto" />}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
                <SidebarMenuItem>
                    <SidebarMenuButton asChild isActive={url === '/agents'} tooltip="Ver todos os agentes" className="h-8 rounded-lg text-muted-foreground">
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

export default function AppLayout({ children, breadcrumbs, wide = false }: { children: ReactNode; breadcrumbs?: Crumb[]; wide?: boolean }) {
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

    const isActive = (href: string) => (href === '/' ? url === '/' : url === href || url.startsWith(`${href}/`));
    const counts = { approvals: auth.pending_approvals, notifications: auth.unread_notifications };
    const allNav = [...topNav, ...workNav, ...areasNav, ...companyNav];
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
                        <NavGroup label="Áreas" items={areasNav.filter((item) => !item.managersOnly || user?.is_manager)} isActive={isActive} counts={counts} />
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
                        <Breadcrumb className="min-w-0">
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

                    <main className={cn('mx-auto flex w-full flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8', wide ? 'max-w-[90rem]' : 'max-w-6xl')}>{children}</main>
                </SidebarInset>
                <Toaster position="bottom-right" />
            </SidebarProvider>
        </TooltipProvider>
    );
}
