import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, ExternalLink, Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { AdminBadge } from '@/Components/AdminBadge';
import { Field } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { AuthLayout, AuthStatus, type LoginBrand } from '@/Layouts/AuthLayout';

export default function ForgotPassword({
    brand,
    admin,
    status,
    dev_reset_url,
}: {
    brand: LoginBrand | null;
    admin: boolean;
    status: string | null;
    dev_reset_url: string | null;
}) {
    const form = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/forgot-password');
    };

    return (
        <AuthLayout
            title="Recuperar acesso"
            brand={brand}
            eyebrow={admin ? <AdminBadge /> : undefined}
            heading="Recuperar acesso"
            description="Indique o email da sua conta e enviamos um link para definir uma nova palavra-passe."
        >
            <AuthStatus message={status} />
            {dev_reset_url && (
                <div className="-mt-3 mb-6 rounded-lg border border-dashed px-3.5 py-3 text-sm">
                    <p className="text-muted-foreground">Ambiente local: o email fica só no log, por isso o link aparece aqui.</p>
                    <a
                        href={dev_reset_url}
                        className="mt-2 inline-flex items-center gap-1.5 font-medium text-primary underline-offset-4 hover:underline"
                    >
                        Abrir o link de recuperação
                        <ExternalLink className="size-3.5" />
                    </a>
                </div>
            )}

            <form onSubmit={submit} className="flex flex-col gap-5">
                <Field id="email" label="Email" error={form.errors.email}>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        autoFocus
                        required
                        placeholder="nome@empresa.co.mz"
                        className="h-10"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        aria-invalid={!!form.errors.email}
                    />
                </Field>

                <Button type="submit" size="lg" className="w-full" disabled={form.processing}>
                    {form.processing && <Loader2 className="size-4 animate-spin" />}
                    Enviar link
                </Button>
            </form>

            <Link href="/login" className="mt-8 flex items-center justify-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-3.5" />
                Voltar a entrar
            </Link>
        </AuthLayout>
    );
}
