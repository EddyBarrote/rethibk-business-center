import { Head } from '@inertiajs/react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { ReactNode } from 'react';

import { PRODUCT_NAME, RethinkMark } from '@/Components/RethinkMark';
import { Button } from '@/Components/ui/button';
import { type Appearance, useAppearance } from '@/lib/appearance';

export interface LoginBrand {
    name: string;
    color: string | null;
    logo_url: string | null;
}

const nextAppearance: Record<Appearance, { next: Appearance; label: string; icon: typeof Sun }> = {
    system: { next: 'light', label: 'Tema: sistema', icon: Monitor },
    light: { next: 'dark', label: 'Tema: claro', icon: Sun },
    dark: { next: 'system', label: 'Tema: escuro', icon: Moon },
};

/**
 * Every sign-in screen: one card in the middle of the page, under a soft glow
 * of the theme's primary colour, with the company's logo above it (the
 * product mark when it has none). The same on a phone and on a large screen.
 */
export function AuthLayout({
    title,
    heading,
    description,
    brand,
    eyebrow,
    note,
    children,
}: {
    title: string;
    heading: string;
    description: ReactNode;
    brand?: LoginBrand | null;
    eyebrow?: ReactNode;
    /** A line under the card, such as the way back or how to get an account. */
    note?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="relative flex min-h-svh flex-col overflow-hidden bg-background px-4 sm:px-8">
            <Head title={title} />
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-x-0 top-0 h-[32rem] bg-[radial-gradient(60%_100%_at_50%_0%,color-mix(in_oklch,var(--primary)_16%,transparent),transparent)]"
            />

            <header className="relative mx-auto flex w-full max-w-6xl justify-end py-5">
                <AppearanceToggle />
            </header>

            <main className="relative flex flex-1 items-center justify-center py-8">
                <div className="w-full max-w-[400px]">
                    <div className="flex flex-col items-center text-center">
                        {brand?.logo_url ? (
                            <img src={brand.logo_url} alt="" className="h-12 max-w-48 object-contain" />
                        ) : (
                            <RethinkMark className="size-14 rounded-2xl shadow-[0_10px_30px_-8px_color-mix(in_oklch,var(--primary)_60%,transparent)]" />
                        )}
                        {eyebrow && <div className="mt-6 -mb-2">{eyebrow}</div>}
                        <h1 className="mt-6 text-3xl font-semibold tracking-tight">{heading}</h1>
                        <p className="mt-2 text-[15px] text-balance text-muted-foreground">{description}</p>
                    </div>

                    <div className="mt-8 rounded-3xl border bg-card p-6 shadow-[0_20px_60px_-20px_rgb(15_23_42/0.18)] sm:p-8 dark:shadow-none">
                        {children}
                    </div>

                    {note && <div className="mt-6 text-center text-sm text-muted-foreground">{note}</div>}
                </div>
            </main>

            <footer className="relative mx-auto flex w-full max-w-6xl flex-col items-center justify-between gap-2 border-t py-5 text-xs text-muted-foreground sm:flex-row">
                <span className="flex items-center gap-2 text-center sm:text-left">
                    <RethinkMark className="hidden size-5 rounded-[5px] sm:flex" />
                    <span>
                        {PRODUCT_NAME} · Desenvolvido por <span className="font-medium text-foreground">Rethink Technologies</span>
                    </span>
                </span>
                <span className="font-mono">© {new Date().getFullYear()}</span>
            </footer>
        </div>
    );
}

function AppearanceToggle() {
    const { appearance, setAppearance } = useAppearance();
    const current = nextAppearance[appearance];
    const Icon = current.icon;

    return (
        <Button
            variant="ghost"
            size="icon"
            className="size-8 text-muted-foreground"
            onClick={() => setAppearance(current.next)}
            title={current.label}
            aria-label={current.label}
        >
            <Icon className="size-4" />
        </Button>
    );
}

/** A server message (link sent, password changed) shown above the form. */
export function AuthStatus({ message }: { message?: string | null }) {
    if (!message) {
        return null;
    }

    return <div className="mb-6 rounded-lg border border-primary/25 bg-primary/5 px-3.5 py-3 text-sm text-foreground">{message}</div>;
}
