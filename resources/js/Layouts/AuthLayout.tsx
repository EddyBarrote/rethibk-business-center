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
 * Every sign-in screen: the form in a glass card in the middle of the page,
 * over a field of small dots. Theme tokens only, so light and dark come out of
 * the same code.
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
        <div className="relative flex min-h-svh flex-col overflow-hidden bg-background px-4 py-6 sm:px-8">
            <Head title={title} />
            <Backdrop />

            <header className="relative flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <RethinkMark className="size-8 rounded-lg" />
                    <span className="font-semibold tracking-tight">{PRODUCT_NAME}</span>
                </div>
                <AppearanceToggle />
            </header>

            <main className="relative flex flex-1 items-center justify-center py-10">
                <div className="w-full max-w-[25rem]">
                    <div className="flex flex-col items-center text-center">
                        {eyebrow && <div className="mb-6">{eyebrow}</div>}
                        <h1 className="text-4xl leading-[1.05] font-semibold tracking-tight text-balance sm:text-5xl">{heading}</h1>
                        <p className="mt-3 text-[15px] text-balance text-muted-foreground">{description}</p>
                    </div>

                    <div className="mt-8 rounded-3xl border bg-card/70 p-6 shadow-[0_24px_70px_-24px_rgb(15_23_42/0.25)] backdrop-blur-xl sm:p-7 dark:bg-card/60 dark:shadow-[0_24px_70px_-24px_rgb(0_0_0/0.7)]">
                        {brand?.logo_url && <img src={brand.logo_url} alt="" className="mx-auto mb-6 h-9 max-w-40 object-contain" />}
                        {children}
                    </div>

                    {note && <div className="mt-6 text-center text-sm text-muted-foreground">{note}</div>}
                </div>
            </main>

            <footer className="relative text-center text-xs text-muted-foreground">
                Desenvolvido por <span className="font-medium text-foreground">Rethink Technologies</span> · © {new Date().getFullYear()}
            </footer>
        </div>
    );
}

/** The page behind everything: a field of small dots that fades at the edges, and two soft glows. */
function Backdrop() {
    return (
        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
            <div className="absolute inset-0 bg-[radial-gradient(color-mix(in_oklch,var(--foreground)_20%,transparent)_1px,transparent_1.5px)] [mask-image:radial-gradient(ellipse_80%_75%_at_50%_45%,black,transparent)] bg-[size:22px_22px]" />
            <div className="absolute -top-64 left-1/2 size-[44rem] -translate-x-1/2 rounded-full bg-primary/15 blur-[140px] dark:bg-primary/25" />
            <div className="absolute -right-48 -bottom-56 size-[40rem] rounded-full bg-primary/10 blur-[140px] dark:bg-primary/15" />
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
