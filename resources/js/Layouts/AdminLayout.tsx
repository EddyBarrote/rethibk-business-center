import { Head, Link, router, usePage } from '@inertiajs/react';
import { Building2, LogOut, ShieldCheck } from 'lucide-react';
import type { ReactNode } from 'react';

import { RethinkMark } from '@/Components/RethinkMark';
import { Button } from '@/Components/ui/button';
import type { SharedProps } from '@/types';

/**
 * Super admin console (Rethink operators), outside any tenant.
 */
export default function AdminLayout({ title, children }: { title: string; children: ReactNode }) {
    const { admin, flash } = usePage<SharedProps>().props;

    return (
        <div className="min-h-screen bg-background">
            <Head title={`${title} · Administração`} />

            <header className="border-b bg-sidebar">
                <div className="mx-auto flex h-14 max-w-6xl items-center gap-4 px-4 sm:px-6 lg:px-8">
                    <Link href="/tenants" className="flex items-center gap-3">
                        <RethinkMark />
                        <span className="text-sm font-semibold">Administração da plataforma</span>
                    </Link>
                    <nav className="ml-4 hidden items-center gap-1 sm:flex">
                        <Link href="/tenants" className="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm hover:bg-sidebar-accent/60">
                            <Building2 className="size-4" />
                            Organizações
                        </Link>
                    </nav>
                    <div className="ml-auto flex items-center gap-3">
                        {admin && (
                            <span className="hidden items-center gap-1.5 text-sm text-muted-foreground sm:flex">
                                <ShieldCheck className="size-4" />
                                {admin.name}
                            </span>
                        )}
                        <Button variant="ghost" size="sm" onClick={() => router.post('/logout')}>
                            <LogOut />
                            Sair
                        </Button>
                    </div>
                </div>
            </header>

            <main className="mx-auto flex max-w-6xl flex-col gap-6 p-4 sm:p-6 lg:p-8">
                {flash.success && <div className="rounded-md border border-primary/20 bg-accent px-4 py-3 text-sm text-accent-foreground">{flash.success}</div>}
                {flash.error && (
                    <div className="rounded-md border border-destructive/30 bg-destructive/10 px-4 py-3 text-sm text-destructive">{flash.error}</div>
                )}
                {children}
            </main>
        </div>
    );
}
