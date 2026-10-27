import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ImageUp, Trash2 } from 'lucide-react';
import { useRef, useState, type FormEvent } from 'react';

import { Property } from '@/Components/Blocks';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { PersonAvatar } from '@/Components/PersonAvatar';
import { PhotoCropper } from '@/Components/PhotoCropper';
import { SettingsBlock } from '@/Components/SettingsBlock';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { Label } from '@/Components/ui/label';
import { Switch } from '@/Components/ui/switch';
import SettingsLayout from '@/Layouts/SettingsLayout';
import type { SharedProps } from '@/types';

interface Props {
    profile: {
        name: string;
        email: string;
        job_title: string | null;
        role: string;
        department: string | null;
    };
    email_images_ask: boolean;
}

/** A minha conta › Perfil: the person's own photo, name and password, and how emails show their images. */
export default function Profile({ profile, email_images_ask }: Props) {
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

            <PhotoBlock name={profile.name} />

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

            <SettingsBlock title="Email" description="Como os emails aparecem no ecrã Email.">
                <div className="flex items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <Label htmlFor="email_images_ask">Perguntar antes de mostrar imagens</Label>
                        <p className="text-sm text-muted-foreground">
                            Por defeito as imagens dos emails carregam sozinhas, excepto no Spam. Ligue para as esconder até carregar em «Mostrar
                            imagens»: assim o remetente não sabe que abriu o email.
                        </p>
                    </div>
                    <Switch
                        id="email_images_ask"
                        checked={email_images_ask}
                        onCheckedChange={(ask) => router.put('/settings/profile/email-images', { ask }, { preserveScroll: true })}
                    />
                </div>
            </SettingsBlock>
        </SettingsLayout>
    );
}

/**
 * The person's photo (docs/DECISOES.md, "Foto de perfil"): chosen from the
 * computer, framed in a circle, and shown wherever their initials were.
 */
function PhotoBlock({ name }: { name: string }) {
    const { auth, errors } = usePage<SharedProps & { errors: Record<string, string> }>().props;
    const url = auth.user?.avatar_url ?? null;
    const input = useRef<HTMLInputElement>(null);
    const [picked, setPicked] = useState<File | null>(null);
    const [sending, setSending] = useState(false);

    const send = (photo: File) => {
        router.post(
            '/settings/profile/avatar',
            { avatar: photo },
            {
                preserveScroll: true,
                forceFormData: true,
                onStart: () => setSending(true),
                onSuccess: () => setPicked(null),
                onFinish: () => setSending(false),
            },
        );
    };

    return (
        <SettingsBlock
            title="Foto"
            description="Aparece onde hoje aparecem as suas iniciais: na barra lateral, nas conversas, nas tarefas e no organograma."
        >
            <div className="flex items-center gap-5">
                <PersonAvatar name={name} url={url} className="size-20 rounded-full text-xl" />
                <div className="flex flex-col items-start gap-2">
                    <div className="flex flex-wrap gap-2">
                        <Button type="button" variant="outline" size="sm" onClick={() => input.current?.click()}>
                            <ImageUp />
                            {url ? 'Mudar foto' : 'Carregar foto'}
                        </Button>
                        {url && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="text-muted-foreground"
                                onClick={() => router.delete('/settings/profile/avatar', { preserveScroll: true })}
                            >
                                <Trash2 />
                                Retirar foto
                            </Button>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">PNG, JPG ou WebP, até 8 MB.</p>
                    {errors.avatar && <p className="text-xs text-destructive">{errors.avatar}</p>}
                </div>
            </div>
            <input
                ref={input}
                type="file"
                accept="image/png,image/jpeg,image/webp"
                className="hidden"
                onChange={(event) => {
                    setPicked(event.target.files?.[0] ?? null);
                    event.target.value = '';
                }}
            />
            <PhotoCropper file={picked} onClose={() => setPicked(null)} onCrop={send} processing={sending} />
        </SettingsBlock>
    );
}
