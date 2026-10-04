import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, ChevronsUpDown, LayoutDashboard, LogOut, type LucideIcon, Monitor, Moon, Puzzle, ShieldCheck, Sun } from 'lucide-react';
import { Fragment, type ReactNode, useEffect } from 'react';
import { toast } from 'sonner';

import { Monogram } from '@/Components/Blocks';
import { RethinkMark } from '@/Components/RethinkMark';
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
 * Super admin console (Rethink operators), outside any tenant. Same shell as
 * AppLayout: collapsible sidebar, breadcrumb top bar, flash messages as toasts.
 */

interface NavItem {
    label: string;
    href: string;
    icon: LucideIcon;
    exact?: boolean;
}

export interface Crumb {
    label: string;
    href?: string;
}

const platformNav: NavItem[] = [{ label: 'Organizações', href: '/tenants', icon: Building2 }];

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

function NavGroup({ label, items, isActive }: { label?: string; items: NavItem[]; isActive: (item: NavItem) => boolean }) {
    return (
        <SidebarGroup className="py-1">
            {label && (
                <SidebarGroupLabel className="h-7 text-[10px] font-medium tracking-widest text-muted-foreground/70 uppercase">
                    {label}
                </SidebarGroupLabel>
            )}
            <SidebarMenu className="gap-0.5">
                {items.map((item) => (
                    <SidebarMenuItem key={item.href}>
                        <SidebarMenuButton
                            asChild
                            isActive={isActive(item)}
                            tooltip={item.label}
                            className="h-8 rounded-lg font-medium text-sidebar-foreground/85"
                        >
                            <Link href={item.href}>
                                <item.icon />
                                <span>{item.label}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}

function UserMenu() {
    const { admin } = usePage<SharedProps>().props;
    const { appearance, setAppearance } = useAppearance();
    const { isMobile } = useSidebar();

    if (!admin) {
        return null;
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton size="lg" className="rounded-lg data-[state=open]:bg-sidebar-accent">
                            <Monogram name={admin.name} className="size-8" />
                            <div className="grid flex-1 text-left leading-tight">
                                <span className="truncate text-sm font-medium">{admin.name}</span>
                                <span className="truncate text-xs text-muted-foreground">Super administrador</span>
                            </div>
                            <ChevronsUpDown className="ml-auto size-4 text-muted-foreground" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent side={isMobile ? 'bottom' : 'right'} align="end" className="w-60">
                        <DropdownMenuLabel className="font-normal">
                            <p className="flex items-center gap-1.5 text-sm font-medium">
                                <ShieldCheck className="size-3.5 text-muted-foreground" />
                                {admin.name}
                            </p>
                            <p className="text-xs text-muted-foreground">{admin.email}</p>
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

export default function AdminLayout({
    title,
    children,
    breadcrumbs,
    wide = false,
}: {
    title: string;
    children: ReactNode;
    breadcrumbs?: Crumb[];
    wide?: boolean;
}) {
    const page = usePage<SharedProps>();
    const { flash } = page.props;
    const url = page.url.split('?')[0];

    useEffect(() => {
        if (flash.success) {
            toast.success(flash.success);
        }
        if (flash.error) {
            toast.error(flash.error);
        }
    }, [flash.success, flash.error]);

    // Inside one organisation (/tenants/{id}/…), show its own pages too.
    const tenantId = url.match(/^\/tenants\/(\d+)(?:\/|$)/)?.[1];
    const tenantNav: NavItem[] = tenantId
        ? [
              { label: 'Visão geral', href: `/tenants/${tenantId}`, icon: LayoutDashboard, exact: true },
              { label: 'Capacidades', href: `/tenants/${tenantId}/capabilities`, icon: Puzzle },
          ]
        : [];

    const isActive = (item: NavItem) => (item.exact ? url === item.href : url === item.href || url.startsWith(`${item.href}/`));
    const isPlatformActive = (item: NavItem) => (tenantId ? false : isActive(item));
    const trail: Crumb[] = breadcrumbs ?? [{ label: title }];

    return (
        <TooltipProvider delayDuration={300}>
            <Head title={`${title} · Administração`} />
            <SidebarProvider defaultOpen={sidebarCookieOpen()}>
                <Sidebar collapsible="icon" variant="sidebar">
                    <SidebarHeader className="px-3 pt-3 pb-1">
                        <SidebarMenu>
                            <SidebarMenuItem>
                                <SidebarMenuButton size="lg" asChild className="rounded-lg">
                                    <Link href="/tenants">
                                        <RethinkMark className="size-8" />
                                        <div className="grid flex-1 text-left leading-tight">
                                            <span className="truncate text-sm font-semibold">Administração</span>
                                            <span className="truncate text-xs text-muted-foreground">Plataforma de Agentes</span>
                                        </div>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        </SidebarMenu>
                    </SidebarHeader>

                    <SidebarContent className="gap-1 px-1">
                        <NavGroup label="Plataforma" items={platformNav} isActive={isPlatformActive} />
                        {tenantNav.length > 0 && <NavGroup label="Organização" items={tenantNav} isActive={isActive} />}
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
                        <span className="ml-auto hidden items-center gap-1.5 text-xs text-muted-foreground sm:flex">
                            <ShieldCheck className="size-3.5" />
                            Consola de operadores
                        </span>
                    </header>

                    <main className={cn('mx-auto flex w-full flex-col gap-8 px-4 py-6 sm:px-6 lg:px-8', wide ? 'max-w-[90rem]' : 'max-w-6xl')}>
                        {children}
                    </main>
                </SidebarInset>
                <Toaster position="bottom-right" />
            </SidebarProvider>
        </TooltipProvider>
    );
}
