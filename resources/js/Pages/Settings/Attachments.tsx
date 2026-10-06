import { Head, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { InputError } from '@/Components/InputError';
import { PageHeader } from '@/Components/PageHeader';
import { SettingsBlock } from '@/Components/SettingsBlock';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import SettingsLayout from '@/Layouts/SettingsLayout';

interface Limits {
    max_mb: number;
    max_files: number;
    audio_max_minutes: number;
    types: string[];
}

interface Props {
    settings: Limits;
    types: { value: string; label: string; extensions: string[] }[];
    ceiling: Omit<Limits, 'types'>;
    defaults: Limits;
    provider: string;
    native: string[];
}

/** What people may send in conversations with agents (docs/DECISOES.md, "Anexos nas conversas"). */
export default function AttachmentSettings({ settings, types, ceiling, defaults, provider, native }: Props) {
    const form = useForm<Limits>(settings);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.put('/settings/attachments', { preserveScroll: true });
    };

    const reads = (prefixes: string[]) => native.some((mime) => prefixes.some((prefix) => mime.startsWith(prefix)));
    const nativeNote = [
        reads(['image/']) && 'imagens',
        reads(['application/pdf']) && 'PDF',
        reads(['audio/']) && 'áudio',
        reads(['video/']) && 'vídeo',
    ].filter(Boolean) as string[];

    return (
        <SettingsLayout>
            <Head title="Anexos" />
            <PageHeader
                title="Anexos"
                description="O que as pessoas podem enviar nas conversas com os agentes: imagens, PDF, documentos e áudio, gravado ou em ficheiro."
            />

            <form onSubmit={submit} className="flex flex-col gap-8">
                <SettingsBlock
                    title="Limites"
                    description="Valem para cada mensagem. Os ficheiros carregam por partes, por isso o tamanho não depende do servidor."
                >
                    <div className="grid gap-5 sm:grid-cols-3">
                        <Field
                            id="max_mb"
                            label="Tamanho por ficheiro (MB)"
                            error={form.errors.max_mb}
                            hint={`Até ${ceiling.max_mb} MB. Por omissão ${defaults.max_mb}.`}
                        >
                            <Input
                                id="max_mb"
                                type="number"
                                min={1}
                                max={ceiling.max_mb}
                                value={form.data.max_mb}
                                onChange={(e) => form.setData('max_mb', Number(e.target.value))}
                            />
                        </Field>
                        <Field
                            id="max_files"
                            label="Ficheiros por mensagem"
                            error={form.errors.max_files}
                            hint={`Até ${ceiling.max_files}. Por omissão ${defaults.max_files}.`}
                        >
                            <Input
                                id="max_files"
                                type="number"
                                min={1}
                                max={ceiling.max_files}
                                value={form.data.max_files}
                                onChange={(e) => form.setData('max_files', Number(e.target.value))}
                            />
                        </Field>
                        <Field
                            id="audio_max_minutes"
                            label="Gravação mais longa (min)"
                            error={form.errors.audio_max_minutes}
                            hint={`Até ${ceiling.audio_max_minutes}. Por omissão ${defaults.audio_max_minutes}.`}
                        >
                            <Input
                                id="audio_max_minutes"
                                type="number"
                                min={1}
                                max={ceiling.audio_max_minutes}
                                value={form.data.audio_max_minutes}
                                onChange={(e) => form.setData('audio_max_minutes', Number(e.target.value))}
                            />
                        </Field>
                    </div>
                </SettingsBlock>

                <SettingsBlock
                    title="Tipos aceites"
                    description={
                        <>
                            O modelo dos agentes ({provider}) lê {nativeNote.length ? nativeNote.join(', ') : 'só texto'} directamente. Os outros
                            tipos chegam-lhe como texto: o texto dos documentos, a transcrição do áudio.
                        </>
                    }
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        {types.map((type) => (
                            <label
                                key={type.value}
                                className="flex items-start gap-3 rounded-lg border p-3 text-sm has-[:checked]:border-primary/50 has-[:checked]:bg-primary/5"
                            >
                                <Checkbox
                                    className="mt-0.5"
                                    checked={form.data.types.includes(type.value)}
                                    onCheckedChange={(on) =>
                                        form.setData(
                                            'types',
                                            on === true ? [...form.data.types, type.value] : form.data.types.filter((t) => t !== type.value),
                                        )
                                    }
                                />
                                <span className="min-w-0">
                                    <span className="font-medium">{type.label}</span>
                                    <span className="block text-xs text-muted-foreground">{type.extensions.map((e) => `.${e}`).join(' ')}</span>
                                </span>
                            </label>
                        ))}
                    </div>
                    <InputError message={form.errors.types} />
                    <p className="text-xs text-muted-foreground">
                        Os anexos seguem as regras da conversa: vê-os quem pode ler a conversa. Os ficheiros carregados e nunca enviados apagam-se ao
                        fim de um dia.
                    </p>
                </SettingsBlock>

                <div className="flex items-center justify-end gap-3">
                    <span className="text-xs text-muted-foreground">{form.isDirty ? 'Alterações por guardar.' : 'Sem alterações.'}</span>
                    <Button type="submit" disabled={form.processing || !form.isDirty}>
                        {form.processing && <Loader2 className="animate-spin" />}
                        Guardar
                    </Button>
                </div>
            </form>
        </SettingsLayout>
    );
}
