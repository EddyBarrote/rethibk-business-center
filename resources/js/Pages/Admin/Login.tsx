import { usePage } from '@inertiajs/react';

import { AdminBadge } from '@/Components/AdminBadge';
import { SignInForm } from '@/Components/SignInForm';
import { AuthLayout, AuthStatus } from '@/Layouts/AuthLayout';
import type { SharedProps } from '@/types';

export default function AdminLogin() {
    const { flash } = usePage<SharedProps>().props;

    return (
        <AuthLayout
            title="Administração"
            heading="Administração da plataforma"
            description="Acesso reservado aos operadores da Rethink Technologies."
            eyebrow={<AdminBadge />}
        >
            <AuthStatus message={flash.success} />
            <SignInForm />
        </AuthLayout>
    );
}
