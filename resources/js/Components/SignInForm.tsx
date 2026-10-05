import { Link, useForm } from '@inertiajs/react';
import { KeyRound, Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { PasswordInput } from '@/Components/PasswordInput';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';

/** Email, password and "keep me signed in", with the way back in when the password is forgotten. */
export function SignInForm() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <>
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

            <Link
                href="/forgot-password"
                className="mt-6 flex items-center justify-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-sm text-muted-foreground transition-colors hover:border-primary/40 hover:bg-primary/5 hover:text-foreground"
            >
                <KeyRound className="size-4 text-primary" />
                Esqueceu-se da palavra-passe? <span className="font-medium text-primary">Recuperar acesso</span>
            </Link>
        </>
    );
}
