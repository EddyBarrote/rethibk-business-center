import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { RethinkMark } from '@/Components/RethinkMark';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import type { SharedProps } from '@/types';

export default function Login() {
    const { tenant } = usePage<SharedProps>().props;
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-muted/40 p-4">
            <Head title="Entrar" />

            <div className="flex w-full max-w-sm flex-col gap-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <RethinkMark className="size-10 rounded-lg text-lg" />
                    <div className="space-y-0.5">
                        <p className="text-sm font-semibold">{tenant?.name ?? 'Plataforma de Agentes'}</p>
                        <p className="text-xs text-muted-foreground">Plataforma de Agentes · Rethink</p>
                    </div>
                </div>

                <div className="rounded-xl border bg-card p-6 shadow-sm">
                    <div className="mb-6 space-y-1">
                        <h1 className="text-lg font-semibold tracking-tight">Entrar</h1>
                        <p className="text-sm text-muted-foreground">Use o email e a palavra-passe da sua conta.</p>
                    </div>

                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <Field id="email" label="Email" error={form.errors.email}>
                            <Input
                                id="email"
                                type="email"
                                autoComplete="username"
                                autoFocus
                                required
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                                aria-invalid={!!form.errors.email}
                            />
                        </Field>

                        <Field id="password" label="Palavra-passe" error={form.errors.password}>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                value={form.data.password}
                                onChange={(event) => form.setData('password', event.target.value)}
                                aria-invalid={!!form.errors.password}
                            />
                        </Field>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="remember"
                                checked={form.data.remember}
                                onCheckedChange={(checked) => form.setData('remember', checked === true)}
                            />
                            <Label htmlFor="remember" className="font-normal text-muted-foreground">
                                Manter sessão iniciada
                            </Label>
                        </div>

                        <Button type="submit" className="mt-2 w-full" disabled={form.processing}>
                            Entrar
                        </Button>
                    </form>
                </div>
            </div>
        </div>
    );
}
