import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import AdminLayout from '@/Layouts/AdminLayout';

export default function TenantsCreate() {
    const form = useForm({ name: '', slug: '', domain: '', owner_name: '', owner_email: '', owner_password: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/tenants');
    };

    const input = (key: keyof typeof form.data, props: React.ComponentProps<typeof Input> = {}) => (
        <Input id={key} value={form.data[key]} onChange={(e) => form.setData(key, e.target.value)} aria-invalid={!!form.errors[key]} {...props} />
    );

    return (
        <AdminLayout title="Nova organização">
            <PageHeader title="Nova organização" description="Cria o tenant, o utilizador proprietário e o catálogo de skills locais." />

            <Card className="max-w-2xl">
                <form onSubmit={submit}>
                    <CardHeader>
                        <CardTitle>Organização</CardTitle>
                        <CardDescription>O subdomínio é o endereço da consola: subdominio.domínio-central.</CardDescription>
                    </CardHeader>
                    <CardContent className="mt-6 grid gap-5 sm:grid-cols-2">
                        <Field id="name" label="Nome" error={form.errors.name}>
                            {input('name', { required: true })}
                        </Field>
                        <Field id="slug" label="Subdomínio" error={form.errors.slug} hint="Letras minúsculas, números e hífenes.">
                            {input('slug', { required: true, placeholder: 'micomoc' })}
                        </Field>
                        <Field id="domain" label="Domínio próprio (opcional)" error={form.errors.domain} className="sm:col-span-2">
                            {input('domain', { placeholder: 'agentes.exemplo.co.mz' })}
                        </Field>
                        <Field id="owner_name" label="Nome do proprietário" error={form.errors.owner_name}>
                            {input('owner_name', { required: true })}
                        </Field>
                        <Field id="owner_email" label="Email do proprietário" error={form.errors.owner_email}>
                            {input('owner_email', { required: true, type: 'email' })}
                        </Field>
                        <Field id="owner_password" label="Palavra-passe inicial" error={form.errors.owner_password} hint="Mínimo de 8 caracteres.">
                            {input('owner_password', { required: true, type: 'password', autoComplete: 'new-password' })}
                        </Field>
                    </CardContent>
                    <CardFooter className="mt-6 justify-end">
                        <Button type="submit" disabled={form.processing}>
                            Criar organização
                        </Button>
                    </CardFooter>
                </form>
            </Card>
        </AdminLayout>
    );
}
