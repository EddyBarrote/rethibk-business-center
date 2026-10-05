import { useForm } from '@inertiajs/react';
import { Loader2, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { PasswordInput } from '@/Components/PasswordInput';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { AuthLayout } from '@/Layouts/AuthLayout';

export default function AdminLogin() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <AuthLayout
            title="Administração"
            heading="Administração da plataforma"
            description="Acesso reservado aos operadores da Rethink Technologies."
            eyebrow={
                <div className="mb-8 inline-flex items-center gap-1.5 rounded-full border bg-muted/60 px-2.5 py-1 text-xs font-medium text-muted-foreground">
                    <ShieldCheck className="size-3.5 text-primary" />
                    Super administrador
                </div>
            }
        >
            <form onSubmit={submit} className="flex flex-col gap-5">
                <Field id="email" label="Email" error={form.errors.email}>
                    <Input
                        id="email"
                        type="email"
                        autoComplete="username"
                        autoFocus
                        required
                        className="h-10"
                        value={form.data.email}
                        onChange={(e) => form.setData('email', e.target.value)}
                        aria-invalid={!!form.errors.email}
                    />
                </Field>
                <Field id="password" label="Palavra-passe" error={form.errors.password}>
                    <PasswordInput
                        id="password"
                        autoComplete="current-password"
                        required
                        className="h-10"
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                        aria-invalid={!!form.errors.password}
                    />
                </Field>
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
        </AuthLayout>
    );
}
