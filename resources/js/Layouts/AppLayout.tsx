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
    Menu,
    PlugZap,
    ShoppingCart,
    Users,
    X,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

import { RethinkMark } from '@/Components/RethinkMark';
import { Avatar, AvatarFallback } from '@/Components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types';

interface NavItem {
    label: string;
    href: string;
    icon: LucideIcon;
    // Modules that land in later deliveries are shown but not yet clickable.
    soon?: string;
    managersOnly?: boolean;
    badge?: 'approvals';
}

const mainNav: NavItem[] = [
    { label: 'Painel', href: '/', icon: LayoutDashboard },
    { label: 'Aprovações', href: '/approvals', icon: CheckSquare, badge: 'approvals' },
    { label: 'Caixa', href: '/inbox', icon: Inbox },
    { label: 'Briefings', href: '/briefings', icon: FileText },
    { label: 'Documentos', href: '/reports', icon: Files },
    { label: 'Agentes', href: '/agents', icon: Bot },
    { label: 'Execuções', href: '/runs', icon: Activity },
    { label: 'Memória', href: '/knowledge', icon: Library },
];

const areasNav: NavItem[] = [
    { label: 'Concursos', href: '/tenders', icon: Gavel },
    { label: 'Clientes', href: '/clients', icon: Briefcase, managersOnly: true },
    { label: 'Finanças', href: '/finance', icon: Landmark, managersOnly: true },
    { label: 'Compras', href: '/procurement', icon: ShoppingCart },
    { label: 'Contratos', href: '/contracts', icon: FileSignature, managersOnly: true },
];

const settingsNav: NavItem[] = [
    { label: 'Utilizadores', href: '/settings/users', icon: Users, managersOnly: true },
    { label: 'Departamentos', href: '/settings/departments', icon: Building2 },
    { label: 'Ligação ao ERP', href: '/settings/erp', icon: PlugZap, managersOnly: true },
];

function initials(name: string) {
    return name
        .split(' ')
        .filter((part) => /^\p{L}/u.test(part))
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function NavLink({ item, active, count = 0 }: { item: NavItem; active: boolean; count?: number }) {
    const Icon = item.icon;
    const classes = cn(
        'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
        active ? 'bg-sidebar-accent text-sidebar-accent-foreground' : 'text-sidebar-foreground/80 hover:bg-sidebar-accent/60',
    );

    if (item.soon) {
        return (
            <span className={cn(classes, 'cursor-not-allowed opacity-50 hover:bg-transparent')} title={`Disponível na ${item.soon}`}>
                <Icon className="size-4" />
                <span className="flex-1">{item.label}</span>
                <span className="text-[10px] tracking-wide uppercase">{item.soon}</span>
            </span>
        );
    }

    return (
        <Link href={item.href} className={classes}>
            <Icon className="size-4" />
            <span className="flex-1">{item.label}</span>
            {count > 0 && <span className="rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold text-white tabular-nums">{count}</span>}
        </Link>
    );
}

export default function AppLayout({ children }: { children: ReactNode }) {
    const page = usePage<SharedProps>();
    const { auth, tenant, flash } = page.props;
    const url = page.url;
    const [open, setOpen] = useState(false);
    const user = auth.user;

    const isActive = (href: string) => (href === '/' ? url === '/' : url.startsWith(href));
    const visibleSettings = settingsNav.filter((item) => !item.managersOnly || user?.can_manage_tenant);
    const visibleAreas = areasNav.filter((item) => !item.managersOnly || user?.is_manager);
    const bell = (
        <Link href="/notifications" className="relative rounded-md p-2 text-muted-foreground hover:bg-accent hover:text-foreground" aria-label="Notificações">
            <Bell className="size-5" />
            {auth.unread_notifications > 0 && (
                <span className="absolute -top-0.5 -right-0.5 min-w-4 rounded-full bg-primary px-1 text-center text-[10px] leading-4 font-semibold text-primary-foreground tabular-nums">
                    {auth.unread_notifications > 99 ? '99+' : auth.unread_notifications}
                </span>
            )}
        </Link>
    );

    const sidebar = (
        <div className="flex h-full flex-col gap-6 overflow-y-auto p-4">
            <div className="flex items-center gap-3 px-1">
                <RethinkMark />
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{tenant?.name ?? 'Plataforma'}</p>
                    <p className="text-xs text-muted-foreground">Plataforma de Agentes</p>
                </div>
                <span className="hidden lg:block">{bell}</span>
            </div>

            <nav className="flex flex-1 flex-col gap-6">
                <div className="flex flex-col gap-1">
                    {mainNav.map((item) => (
                        <NavLink key={item.href} item={item} active={isActive(item.href)} count={item.badge ? auth.pending_approvals : 0} />
                    ))}
                </div>

                <div className="flex flex-col gap-1">
                    <p className="px-3 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">Áreas</p>
                    {visibleAreas.map((item) => (
                        <NavLink key={item.href} item={item} active={isActive(item.href)} />
                    ))}
                </div>

                <div className="flex flex-col gap-1">
                    <p className="px-3 pb-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">Definições</p>
                    {visibleSettings.map((item) => (
                        <NavLink key={item.href} item={item} active={isActive(item.href)} />
                    ))}
                </div>
            </nav>

            {user && (
                <DropdownMenu>
                    <DropdownMenuTrigger className="flex w-full items-center gap-3 rounded-md p-2 text-left outline-none hover:bg-sidebar-accent/60 focus-visible:ring-[3px] focus-visible:ring-ring/50">
                        <Avatar>
                            <AvatarFallback>{initials(user.name)}</AvatarFallback>
                        </Avatar>
                        <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium">{user.name}</p>
                            <p className="truncate text-xs text-muted-foreground">{user.role_label}</p>
                        </div>
                        <ChevronsUpDown className="size-4 text-muted-foreground" />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-56">
                        <DropdownMenuLabel className="font-normal">
                            <p className="text-sm font-medium">{user.name}</p>
                            <p className="text-xs text-muted-foreground">{user.email}</p>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onSelect={() => router.post('/logout')}>
                            <LogOut />
                            Terminar sessão
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );

    return (
        <div className="min-h-screen bg-background">
            <aside className="fixed inset-y-0 left-0 hidden w-64 border-r bg-sidebar lg:block">{sidebar}</aside>

            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="absolute inset-0 bg-black/40" onClick={() => setOpen(false)} />
                    <aside className="absolute inset-y-0 left-0 w-64 border-r bg-sidebar">{sidebar}</aside>
                </div>
            )}

            <div className="lg:pl-64">
                <header className="sticky top-0 z-30 flex h-14 items-center gap-3 border-b bg-background/90 px-4 backdrop-blur lg:hidden">
                    <button type="button" onClick={() => setOpen(!open)} className="rounded-md p-2 hover:bg-accent" aria-label="Menu">
                        {open ? <X className="size-5" /> : <Menu className="size-5" />}
                    </button>
                    <p className="flex-1 text-sm font-semibold">{tenant?.name}</p>
                    {bell}
                </header>

                <main className="mx-auto flex max-w-6xl flex-col gap-6 p-4 sm:p-6 lg:p-8">
                    {flash.success && (
                        <div className="rounded-md border border-primary/20 bg-accent px-4 py-3 text-sm text-accent-foreground">{flash.success}</div>
                    )}
                    {flash.error && (
                        <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">{flash.error}</div>
                    )}
                    {children}
                </main>
            </div>
        </div>
    );
}
