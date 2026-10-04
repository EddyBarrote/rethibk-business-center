import { Head, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { RethinkMark } from '@/Components/RethinkMark';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';

export default function AdminLogin() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="flex min-h-svh flex-col items-center justify-center bg-muted/40 p-4">
            <Head title="Administração" />

            <div className="flex w-full max-w-sm flex-col gap-6">
                <div className="flex flex-col items-center gap-3 text-center">
                    <RethinkMark className="size-10 rounded-lg text-lg" />
                    <div className="space-y-0.5">
                        <p className="text-sm font-semibold">Administração da plataforma</p>
                        <p className="text-xs text-muted-foreground">Plataforma de Agentes · Operadores Rethink</p>
                    </div>
                </div>

                <div className="rounded-xl border bg-card p-6 shadow-sm">
                    <div className="mb-6 space-y-1">
                        <h1 className="flex items-center gap-2 text-lg font-semibold tracking-tight">
                            <ShieldCheck className="size-4 text-muted-foreground" />
                            Entrar
                        </h1>
                        <p className="text-sm text-muted-foreground">Acesso reservado a super administradores.</p>
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
                                onChange={(e) => form.setData('email', e.target.value)}
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
                                onChange={(e) => form.setData('password', e.target.value)}
                                aria-invalid={!!form.errors.password}
                            />
                        </Field>
                        <Button type="submit" className="mt-2 w-full" disabled={form.processing}>
                            Entrar
                        </Button>
                    </form>
                </div>
            </div>
        </div>
    );
}
