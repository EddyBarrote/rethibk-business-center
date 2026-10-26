import { usePage } from '@inertiajs/react';

import { SignInForm } from '@/Components/SignInForm';
import { AuthLayout, AuthStatus, type LoginBrand } from '@/Layouts/AuthLayout';
import type { SharedProps } from '@/types';

/** Bom dia, boa tarde or boa noite, by the clock of whoever opens the page. */
function greeting(): string {
    const hour = new Date().getHours();

    return hour < 12 ? 'Bom dia.' : hour < 19 ? 'Boa tarde.' : 'Boa noite.';
}

export default function Login({ brand }: { brand: LoginBrand | null }) {
    const { flash } = usePage<SharedProps>().props;

    return (
        <AuthLayout
            title="Entrar"
            brand={brand}
            heading={greeting()}
            description="Entre para ver o que a sua equipa de agentes fez."
            note="Não tem conta? Peça acesso ao administrador da sua empresa."
        >
            <AuthStatus message={flash.success} />
            <SignInForm />
        </AuthLayout>
    );
}
