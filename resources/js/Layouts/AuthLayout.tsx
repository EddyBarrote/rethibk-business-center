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
 * Every sign-in screen: the illustrated product panel on the left (large
 * screens only) and the form on the right, under the tenant's own brand.
 */
export function AuthLayout({
    title,
    heading,
    description,
    brand,
    eyebrow,
    children,
}: {
    title: string;
    heading: string;
    description: ReactNode;
    brand?: LoginBrand | null;
    eyebrow?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="grid min-h-svh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)] xl:grid-cols-2">
            <Head title={title} />
            <ProductPanel />

            <div className="flex min-h-svh flex-col px-4 py-6 sm:px-10">
                <header className="flex items-center justify-between">
                    <div className="flex items-center gap-2.5 lg:invisible">
                        <RethinkMark className="size-7" />
                        <span className="text-sm font-semibold tracking-tight">{PRODUCT_NAME}</span>
                    </div>
                    <AppearanceToggle />
                </header>

                <main className="flex flex-1 items-center justify-center py-10">
                    <div className="w-full max-w-sm">
                        {brand ? <TenantIdentity brand={brand} /> : eyebrow}
                        <div className="mb-8 space-y-2">
                            <h1 className="text-2xl font-semibold tracking-tight">{heading}</h1>
                            <p className="text-sm text-muted-foreground">{description}</p>
                        </div>
                        {children}
                    </div>
                </main>

                <footer className="flex flex-col items-center justify-between gap-2 text-xs text-muted-foreground sm:flex-row">
                    <span>
                        Desenvolvido por <span className="font-medium text-foreground">Rethink Technologies</span>
                    </span>
                    <span className="font-mono">© {new Date().getFullYear()}</span>
                </footer>
            </div>
        </div>
    );
}

function ProductPanel() {
    return (
        <aside className="relative hidden overflow-hidden border-r bg-[#F4EEE4] lg:block dark:bg-[#2B2A27]">
            <img src="/images/auth/login-hero.webp" alt="" className="absolute inset-0 size-full object-cover object-top dark:hidden" />
            <img src="/images/auth/login-hero-dark.webp" alt="" className="absolute inset-0 hidden size-full object-cover object-top dark:block" />
            <div className="absolute inset-x-0 bottom-0 h-2/3 bg-gradient-to-t from-[#F4EEE4] via-[#F4EEE4]/80 to-transparent dark:from-[#2B2A27] dark:via-[#2B2A27]/80" />

            <div className="relative flex h-full flex-col justify-between p-10 xl:p-12">
                <div className="flex items-center gap-2.5">
                    <RethinkMark className="size-8" />
                    <span className="font-semibold tracking-tight text-stone-900 dark:text-stone-50">{PRODUCT_NAME}</span>
                </div>

                <div className="max-w-md space-y-6">
                    <p className="text-3xl leading-tight font-semibold tracking-tight text-balance text-stone-900 xl:text-4xl dark:text-stone-50">
                        A sua equipa de agentes, a trabalhar consigo.
                    </p>
                    <ul className="space-y-2.5 text-sm text-stone-700 dark:text-stone-300">
                        {[
                            'Agentes que tratam tarefas, documentos e propostas',
                            'Aprovações humanas onde importa',
                            'Ligado ao seu ERP, email e base de conhecimento',
                        ].map((line) => (
                            <li key={line} className="flex items-center gap-2.5">
                                <span className="size-1.5 rounded-full bg-primary" />
                                {line}
                            </li>
                        ))}
                    </ul>
                </div>
            </div>
        </aside>
    );
}

function TenantIdentity({ brand }: { brand: LoginBrand }) {
    return (
        <div className="mb-8 flex items-center gap-3">
            {brand.logo_url ? (
                <img src={brand.logo_url} alt={brand.name} className="h-10 max-w-40 object-contain" />
            ) : (
                <div
                    className="flex size-10 items-center justify-center rounded-lg bg-primary text-base font-semibold text-primary-foreground"
                    style={brand.color ? { backgroundColor: brand.color, color: '#fff' } : undefined}
                >
                    {brand.name.charAt(0).toUpperCase()}
                </div>
            )}
            {!brand.logo_url && (
                <div className="min-w-0">
                    <p className="truncate text-sm font-semibold">{brand.name}</p>
                    <p className="text-xs text-muted-foreground">{PRODUCT_NAME}</p>
                </div>
            )}
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
