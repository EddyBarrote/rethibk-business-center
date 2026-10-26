import { Head } from '@inertiajs/react';
import { Monitor, Moon, Sun } from 'lucide-react';
import type { ReactNode } from 'react';

import { ProductShowcase } from '@/Components/auth/ProductShowcase';
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
 * Every sign-in screen: the product panel on the left (large screens only),
 * drawn with components, and the form on the right, under the tenant's own brand.
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
                        {eyebrow}
                        {brand && <TenantIdentity brand={brand} />}
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
        <aside className="relative hidden overflow-hidden border-r bg-muted/60 lg:block dark:bg-sidebar">
            <div className="absolute inset-0 bg-[radial-gradient(var(--border)_1px,transparent_1px)] [mask-image:linear-gradient(to_bottom,black,transparent_85%)] bg-[size:20px_20px]" />

            <div className="relative flex h-full flex-col justify-between gap-10 p-10 xl:p-12">
                <div className="flex items-center gap-2.5">
                    <RethinkMark className="size-8" />
                    <span className="font-semibold tracking-tight">{PRODUCT_NAME}</span>
                </div>

                <ProductShowcase className="[@media(max-height:38rem)]:hidden" />

                <div className="max-w-md space-y-6">
                    <p className="text-3xl leading-tight font-semibold tracking-tight text-balance xl:text-4xl">
                        A sua equipa de agentes, a trabalhar consigo.
                    </p>
                    <ul className="space-y-2.5 text-sm text-muted-foreground">
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

/** The company's own logo (Definições › Marca), when it has one; never its name. */
function TenantIdentity({ brand }: { brand: LoginBrand }) {
    if (!brand.logo_url) {
        return null;
    }

    return <img src={brand.logo_url} alt="" className="mb-8 h-10 max-w-40 object-contain" />;
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
