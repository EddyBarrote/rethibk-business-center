import { Head, useForm } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { RethinkMark } from '@/Components/RethinkMark';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';

export default function AdminLogin() {
    const form = useForm({ email: '', password: '', remember: false });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/login', { onFinish: () => form.reset('password') });
    };

    return (
        <div className="flex min-h-screen items-center justify-center bg-sidebar p-4">
            <Head title="Administração" />

            <div className="flex w-full max-w-sm flex-col gap-6">
                <div className="flex items-center justify-center gap-3">
                    <RethinkMark className="size-10 text-lg" />
                    <div>
                        <p className="font-semibold">Administração da plataforma</p>
                        <p className="text-xs text-muted-foreground">Operadores Rethink</p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-xl">
                            <ShieldCheck className="size-5" />
                            Entrar
                        </CardTitle>
                        <CardDescription>Acesso reservado a super administradores.</CardDescription>
                    </CardHeader>
                    <CardContent>
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
                                />
                            </Field>
                            <Button type="submit" className="w-full" disabled={form.processing}>
                                Entrar
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
