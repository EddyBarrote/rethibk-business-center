import { Link, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { PasswordInput } from '@/Components/PasswordInput';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/** Email, password and "keep me signed in", with the way back in when the password is forgotten beside it. */
export function SignInForm() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
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

            <Field id="password" label="Palavra-passe" error={form.errors.password}>
                <PasswordInput
                    id="password"
                    autoComplete="current-password"
                    required
                    className="h-10"
                    value={form.data.password}
                    onChange={(event) => form.setData('password', event.target.value)}
                    aria-invalid={!!form.errors.password}
                />
            </Field>

            <div className="flex items-center justify-between gap-3">
                <div className="flex items-center gap-2">
                    <Checkbox id="remember" checked={form.data.remember} onCheckedChange={(checked) => form.setData('remember', checked === true)} />
                    <Label htmlFor="remember" className="font-normal text-muted-foreground">
                        Manter sessão iniciada
                    </Label>
                </div>
                <Link href="/forgot-password" className="text-sm font-medium text-primary underline-offset-4 hover:underline">
                    Esqueceu-se?
                </Link>
            </div>

            <Button type="submit" size="lg" className="mt-1 w-full" disabled={form.processing}>
                {form.processing && <Loader2 className="size-4 animate-spin" />}
                Entrar
            </Button>
        </form>
    );
}
