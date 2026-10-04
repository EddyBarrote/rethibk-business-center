import { Link, router, usePage } from '@inertiajs/react';
import {
    Bot,
    Building2,
    CheckSquare,
    ChevronsUpDown,
    FileText,
    Inbox,
    LayoutDashboard,
    Library,
    LogOut,
    type LucideIcon,
    Menu,
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
}

const mainNav: NavItem[] = [
    { label: 'Painel', href: '/', icon: LayoutDashboard },
    { label: 'Agentes', href: '/agents', icon: Bot, soon: 'E02' },
    { label: 'Aprovações', href: '/approvals', icon: CheckSquare, soon: 'E02' },
    { label: 'Caixa', href: '/inbox', icon: Inbox, soon: 'E03' },
    { label: 'Memória', href: '/knowledge', icon: Library, soon: 'E02' },
    { label: 'Briefings', href: '/briefings', icon: FileText, soon: 'E04' },
];

const settingsNav: NavItem[] = [
    { label: 'Utilizadores', href: '/settings/users', icon: Users, managersOnly: true },
    { label: 'Departamentos', href: '/settings/departments', icon: Building2 },
];

function initials(name: string) {
    return name
        .split(' ')
        .filter((part) => /^\p{L}/u.test(part))
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase())
        .join('');
}

function NavLink({ item, active }: { item: NavItem; active: boolean }) {
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
            {item.label}
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

    const sidebar = (
        <div className="flex h-full flex-col gap-6 p-4">
            <div className="flex items-center gap-3 px-1">
                <RethinkMark />
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">{tenant?.name ?? 'Plataforma'}</p>
                    <p className="text-xs text-muted-foreground">Plataforma de Agentes</p>
                </div>
            </div>

            <nav className="flex flex-1 flex-col gap-6">
                <div className="flex flex-col gap-1">
                    {mainNav.map((item) => (
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
                    <p className="text-sm font-semibold">{tenant?.name}</p>
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
