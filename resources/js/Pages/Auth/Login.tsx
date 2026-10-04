import { Head, useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { InputError } from '@/Components/InputError';
import { RethinkMark } from '@/Components/RethinkMark';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
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
        <div className="flex min-h-screen items-center justify-center bg-sidebar p-4">
            <Head title="Entrar" />

            <div className="flex w-full max-w-sm flex-col gap-6">
                <div className="flex items-center justify-center gap-3">
                    <RethinkMark className="size-10 text-lg" />
                    <div>
                        <p className="font-semibold">{tenant?.name}</p>
                        <p className="text-xs text-muted-foreground">Plataforma de Agentes · Rethink</p>
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-xl">Entrar</CardTitle>
                        <CardDescription>Use o email e a palavra-passe da sua conta.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="flex flex-col gap-4">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email</Label>
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
                                <InputError message={form.errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Palavra-passe</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    value={form.data.password}
                                    onChange={(event) => form.setData('password', event.target.value)}
                                    aria-invalid={!!form.errors.password}
                                />
                                <InputError message={form.errors.password} />
                            </div>

                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="remember"
                                    checked={form.data.remember}
                                    onCheckedChange={(checked) => form.setData('remember', checked === true)}
                                />
                                <Label htmlFor="remember" className="font-normal">
                                    Manter sessão iniciada
                                </Label>
                            </div>

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
