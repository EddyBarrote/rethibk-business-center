import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { AdminBadge } from '@/Components/AdminBadge';
import { Field } from '@/Components/Field';
import { PasswordInput } from '@/Components/PasswordInput';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { AuthLayout, type LoginBrand } from '@/Layouts/AuthLayout';

export default function ResetPassword({ brand, admin, token, email }: { brand: LoginBrand | null; admin: boolean; token: string; email: string }) {
    const form = useForm({ token, email, password: '', password_confirmation: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
    };

    return (
        <AuthLayout
            title="Nova palavra-passe"
            brand={brand}
            eyebrow={admin ? <AdminBadge /> : undefined}
            heading="Nova palavra-passe"
            description="Escolha uma palavra-passe com pelo menos 8 caracteres."
        >
            <form onSubmit={submit} className="flex flex-col gap-5">
                <Field id="email" label="Email" error={form.errors.email}>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        required
                        className="h-10"
                        value={form.data.email}
                        onChange={(event) => form.setData('email', event.target.value)}
                        aria-invalid={!!form.errors.email}
                    />
                </Field>
                <Field id="password" label="Nova palavra-passe" error={form.errors.password}>
                    <PasswordInput
                        id="password"
                        autoComplete="new-password"
                        autoFocus
                        required
                        className="h-10"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        aria-invalid={!!form.errors.password}
                    />
                </Field>
                <Field id="password_confirmation" label="Confirmar palavra-passe" error={form.errors.password_confirmation}>
                    <PasswordInput
                        id="password_confirmation"
                        autoComplete="new-password"
                        required
                        className="h-10"
                        value={form.data.password_confirmation}
                        onChange={(event) => form.setData('password_confirmation', event.target.value)}
                    />
                </Field>

                <Button type="submit" size="lg" className="mt-1 w-full" disabled={form.processing}>
                    {form.processing && <Loader2 className="size-4 animate-spin" />}
                    Guardar palavra-passe
                </Button>
            </form>

            <Link href="/login" className="mt-8 flex items-center justify-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                <ArrowLeft className="size-3.5" />
                Voltar a entrar
            </Link>
        </AuthLayout>
    );
}
