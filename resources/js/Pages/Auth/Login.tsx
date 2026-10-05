import { Link, useForm, usePage } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { PasswordInput } from '@/Components/PasswordInput';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { AuthLayout, AuthStatus, type LoginBrand } from '@/Layouts/AuthLayout';
import type { SharedProps } from '@/types';

export default function Login({ brand }: { brand: LoginBrand | null }) {
    const { flash } = usePage<SharedProps>().props;
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout
            title="Entrar"
            brand={brand}
            heading="Bem-vindo de volta"
            description={brand ? `Entre na área de trabalho de ${brand.name}.` : 'Entre com o email e a palavra-passe da sua conta.'}
        >
            <AuthStatus message={flash.success} />

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

                <div className="grid gap-2">
                    <div className="flex items-center justify-between">
                        <Label htmlFor="password">Palavra-passe</Label>
                        <Link href="/forgot-password" className="text-xs font-medium text-primary underline-offset-4 hover:underline">
                            Esqueceu-se?
                        </Link>
                    </div>
                    <PasswordInput
                        id="password"
                        autoComplete="current-password"
                        required
                        className="h-10"
                        value={form.data.password}
                        onChange={(event) => form.setData('password', event.target.value)}
                        aria-invalid={!!form.errors.password}
                    />
                    {form.errors.password && <p className="text-sm text-destructive">{form.errors.password}</p>}
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox id="remember" checked={form.data.remember} onCheckedChange={(checked) => form.setData('remember', checked === true)} />
                    <Label htmlFor="remember" className="font-normal text-muted-foreground">
                        Manter sessão iniciada
                    </Label>
                </div>

                <Button type="submit" size="lg" className="mt-1 w-full" disabled={form.processing}>
                    {form.processing && <Loader2 className="size-4 animate-spin" />}
                    Entrar
                </Button>
            </form>

            <p className="mt-8 text-center text-xs text-muted-foreground">Não tem conta? Peça acesso ao administrador da sua empresa.</p>
        </AuthLayout>
    );
}
