import { usePage } from '@inertiajs/react';

import { SignInForm } from '@/Components/SignInForm';
import { AuthLayout, AuthStatus, type LoginBrand } from '@/Layouts/AuthLayout';
import type { SharedProps } from '@/types';

export default function Login({ brand }: { brand: LoginBrand | null }) {
    const { flash } = usePage<SharedProps>().props;

    return (
        <AuthLayout
            title="Entrar"
            brand={brand}
            heading="Bem-vindo de volta"
            description="Entre com o email e a palavra-passe da sua conta."
            note="Não tem conta? Peça acesso ao administrador da sua empresa."
        >
            <AuthStatus message={flash.success} />
            <SignInForm />
        </AuthLayout>
    );
}
