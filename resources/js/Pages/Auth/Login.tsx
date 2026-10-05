import { usePage } from '@inertiajs/react';

import { SignInForm } from '@/Components/SignInForm';
import { AuthLayout, AuthStatus, type LoginBrand } from '@/Layouts/AuthLayout';
import type { SharedProps } from '@/types';

export default function Login({ brand }: { brand: LoginBrand | null }) {
    const { flash } = usePage<SharedProps>().props;

    return (
        <AuthLayout title="Entrar" brand={brand} heading="Bem-vindo de volta" description="Entre com o email e a palavra-passe da sua conta.">
            <AuthStatus message={flash.success} />
            <SignInForm />
            <p className="mt-6 text-center text-xs text-muted-foreground">Não tem conta? Peça acesso ao administrador da sua empresa.</p>
        </AuthLayout>
    );
}
