import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { AuthLayout, AuthStatus, type LoginBrand } from '@/Layouts/AuthLayout';

export default function ForgotPassword({ brand, status }: { brand: LoginBrand | null; status: string | null }) {
    const form = useForm({ email: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/forgot-password');
    };

    return (
        <AuthLayout
            title="Recuperar acesso"
            brand={brand}
            heading="Recuperar acesso"
            description="Indique o email da sua conta e enviamos um link para definir uma nova palavra-passe."
        >
            <AuthStatus message={status} />

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
