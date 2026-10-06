import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

import { Property } from '@/Components/Blocks';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { SettingsBlock } from '@/Components/SettingsBlock';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import SettingsLayout from '@/Layouts/SettingsLayout';

interface Props {
    profile: {
        name: string;
        email: string;
        job_title: string | null;
        role: string;
        department: string | null;
    };
}

/** A minha conta › Perfil: the person's own name and password. */
export default function Profile({ profile }: Props) {
    const details = useForm({ name: profile.name });
    const password = useForm({ current_password: '', password: '', password_confirmation: '' });

    const saveName = (event: FormEvent) => {
        event.preventDefault();
        details.put('/settings/profile', { preserveScroll: true });
    };

    const savePassword = (event: FormEvent) => {
        event.preventDefault();
        password.put('/settings/password', {
            preserveScroll: true,
            onSuccess: () => password.reset(),
            onError: () => password.reset('password', 'password_confirmation'),
        });
    };

    return (
        <SettingsLayout>
            <Head title="Perfil" />
            <PageHeader title="Perfil" description="Como aparece na plataforma e a palavra-passe com que entra." />

            <form onSubmit={saveName} className="flex flex-col gap-8">
                <SettingsBlock title="Os seus dados" description="O email, o papel e o departamento são definidos por quem gere as pessoas.">
                    <Field id="name" label="Nome" error={details.errors.name}>
                        <Input
                            id="name"
                            autoComplete="name"
                            value={details.data.name}
                            onChange={(e) => details.setData('name', e.target.value)}
                            aria-invalid={!!details.errors.name}
                        />
                    </Field>
                    <div>
                        <Property label="Email">
                            <span className="font-mono text-xs">{profile.email}</span>
                        </Property>
                        {profile.job_title && <Property label="Cargo">{profile.job_title}</Property>}
                        <Property label="Papel">{profile.role}</Property>
                        <Property label="Departamento">
                            {profile.department ?? <span className="text-muted-foreground">Sem departamento</span>}
                        </Property>
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" disabled={details.processing || !details.isDirty}>
                            Guardar nome
                        </Button>
                    </div>
                </SettingsBlock>
            </form>

            <form onSubmit={savePassword} className="flex flex-col gap-8">
                <SettingsBlock title="Palavra-passe" description="Use pelo menos 8 caracteres. Depois de mudar, entra com a nova da próxima vez.">
                    <Field id="current_password" label="Palavra-passe actual" error={password.errors.current_password}>
                        <Input
                            id="current_password"
                            type="password"
                            autoComplete="current-password"
                            value={password.data.current_password}
                            onChange={(e) => password.setData('current_password', e.target.value)}
                            aria-invalid={!!password.errors.current_password}
                        />
                    </Field>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field id="password" label="Nova palavra-passe" error={password.errors.password}>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password}
                                onChange={(e) => password.setData('password', e.target.value)}
                                aria-invalid={!!password.errors.password}
                            />
                        </Field>
                        <Field id="password_confirmation" label="Repita a nova" error={password.errors.password_confirmation}>
                            <Input
                                id="password_confirmation"
                                type="password"
                                autoComplete="new-password"
                                value={password.data.password_confirmation}
                                onChange={(e) => password.setData('password_confirmation', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="flex justify-end">
                        <Button type="submit" disabled={password.processing || !password.data.current_password || !password.data.password}>
                            Mudar palavra-passe
                        </Button>
                    </div>
                </SettingsBlock>
            </form>
        </SettingsLayout>
    );
}
