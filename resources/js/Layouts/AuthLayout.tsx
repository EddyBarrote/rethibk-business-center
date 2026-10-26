import { Head } from '@inertiajs/react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { ReactNode } from 'react';

import { AgentsMotion } from '@/Components/auth/AgentsMotion';
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
 * Every sign-in screen: the form in a glass card on the left, over a field of
 * small dots, and on large screens the agents at work on the right. Theme
 * tokens only, so light and dark come out of the same code.
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
        <div className="relative min-h-svh overflow-hidden bg-background">
            <Head title={title} />
            <Backdrop />

            <div className="absolute top-5 right-4 z-10 sm:right-8">
                <AppearanceToggle />
            </div>

            <div className="relative mx-auto grid min-h-svh w-full max-w-7xl lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] lg:gap-10 xl:gap-20">
                <div className="flex min-h-svh flex-col px-4 py-6 sm:px-10 lg:pr-0">
                    <header className="flex items-center gap-2.5">
                        <RethinkMark className="size-8 rounded-lg" />
                        <span className="font-semibold tracking-tight">{PRODUCT_NAME}</span>
                    </header>

                    <main className="flex flex-1 flex-col justify-center py-10">
                        <div className="mx-auto w-full max-w-sm lg:max-w-none">
                            {eyebrow && <div className="mb-6">{eyebrow}</div>}
                            <h1 className="text-4xl leading-[1.05] font-semibold tracking-tight text-balance sm:text-5xl">{heading}</h1>
                            <p className="mt-3 text-[15px] text-muted-foreground">{description}</p>

                            <div className="mt-8 rounded-3xl border bg-card/70 p-6 shadow-[0_24px_70px_-24px_rgb(15_23_42/0.25)] backdrop-blur-xl sm:p-7 dark:bg-card/60 dark:shadow-[0_24px_70px_-24px_rgb(0_0_0/0.7)]">
                                {brand?.logo_url && <img src={brand.logo_url} alt="" className="mb-6 h-9 max-w-40 object-contain" />}
                                {children}
                            </div>

                            {note && <div className="mt-6 text-center text-sm text-muted-foreground lg:text-left">{note}</div>}
                        </div>
                    </main>

                    <footer className="text-center text-xs text-muted-foreground lg:text-left">
                        Desenvolvido por <span className="font-medium text-foreground">Rethink Technologies</span> · © {new Date().getFullYear()}
                    </footer>
                </div>

                <div className="relative hidden items-center justify-center py-16 lg:flex">
                    <AgentsMotion className="w-full max-w-[38rem] [@media(max-height:44rem)]:max-w-[28rem]" />
                </div>
            </div>
        </div>
    );
}

/** The page behind everything: a field of small dots that fades at the edges, and two soft glows. */
function Backdrop() {
    return (
        <div aria-hidden="true" className="pointer-events-none absolute inset-0">
            <div className="absolute inset-0 bg-[radial-gradient(color-mix(in_oklch,var(--foreground)_20%,transparent)_1px,transparent_1.5px)] [mask-image:radial-gradient(ellipse_85%_75%_at_55%_45%,black,transparent)] bg-[size:22px_22px]" />
            <div className="absolute -top-64 left-1/4 size-[44rem] rounded-full bg-primary/15 blur-[140px] dark:bg-primary/25" />
            <div className="absolute -right-48 -bottom-56 size-[40rem] rounded-full bg-chart-5/15 blur-[140px] dark:bg-chart-5/20" />
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
