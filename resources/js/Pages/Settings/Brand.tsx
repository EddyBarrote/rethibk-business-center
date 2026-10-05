import { Head, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { Field } from '@/Components/Field';
import { FileInput } from '@/Components/FileInput';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Input } from '@/Components/ui/input';
import AppLayout from '@/Layouts/AppLayout';

interface Props {
    brand: { name: string; color: string; footer: string | null; has_logo: boolean };
    default_color: string;
}

export default function BrandSettings({ brand, default_color }: Props) {
    const form = useForm<{ color: string; footer: string; logo: File | null; remove_logo: boolean }>({
        color: brand.color,
        footer: brand.footer ?? '',
        logo: null,
        remove_logo: false,
    });
    const [preview, setPreview] = useState<string | null>(brand.has_logo ? '/settings/brand/logo' : null);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/settings/brand', { forceFormData: true, preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Marca" />
            <PageHeader
                title="Marca"
                description="Cor, logótipo e rodapé dos documentos, apresentações, folhas de cálculo e PDFs que os agentes geram."
            />

            <form onSubmit={submit} className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_24rem]">
                <div className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                    <Field
                        id="color"
                        label="Cor principal"
                        error={form.errors.color}
                        hint={`Títulos, cabeçalhos de tabela e capa das apresentações. Por omissão ${default_color}.`}
                    >
                        <div className="flex gap-2">
                            <Input
                                id="color"
                                type="color"
                                className="h-9 w-14 p-1"
                                value={form.data.color}
                                onChange={(e) => form.setData('color', e.target.value)}
                            />
                            <Input
                                className="w-32 font-mono uppercase"
                                value={form.data.color}
                                onChange={(e) => form.setData('color', e.target.value)}
                            />
                        </div>
                    </Field>
                    <Field id="logo" label="Logótipo" error={form.errors.logo}>
                        <FileInput
                            id="logo"
                            accept=".png,.jpg,.jpeg"
                            file={form.data.logo}
                            hint="PNG ou JPG até 2 MB, de preferência com fundo transparente."
                            invalid={!!form.errors.logo}
                            onChange={(file) => {
                                form.setData((d) => ({ ...d, logo: file, remove_logo: false }));
                                setPreview(file ? URL.createObjectURL(file) : brand.has_logo ? '/settings/brand/logo' : null);
                            }}
                        />
                    </Field>
                    {brand.has_logo && (
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={form.data.remove_logo}
                                onCheckedChange={(on) => {
                                    form.setData('remove_logo', on === true);
                                    setPreview(on === true ? null : '/settings/brand/logo');
                                }}
                            />
                            Remover o logótipo actual
                        </label>
                    )}
                    <Field
                        id="footer"
                        label="Rodapé"
                        error={form.errors.footer}
                        hint="Ex.: nome legal, NUIT e endereço. Vazio usa o nome da organização."
                    >
                        <Input
                            id="footer"
                            value={form.data.footer}
                            onChange={(e) => form.setData('footer', e.target.value)}
                            placeholder={brand.name}
                        />
                    </Field>
                    <div className="flex items-center justify-between gap-3 border-t pt-4">
                        <span className="text-xs text-muted-foreground">{form.isDirty ? 'Alterações por guardar.' : 'Sem alterações.'}</span>
                        <Button type="submit" disabled={form.processing || !form.isDirty}>
                            {form.processing && <Loader2 className="animate-spin" />}
                            Guardar
                        </Button>
                    </div>
                </div>

                <div className="flex flex-col gap-2 self-start">
                    <span className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Pré-visualização</span>
                    <div className="aspect-[1/1.414] rounded-xl border bg-white p-6 text-[#1f2328] shadow-sm">
                        <div className="flex items-center justify-between border-b-2 pb-1.5" style={{ borderColor: form.data.color }}>
                            <span className="text-[9px] font-bold tracking-wider uppercase" style={{ color: form.data.color }}>
                                {brand.name}
                            </span>
                            {preview && <img src={preview} alt="" className="h-5" />}
                        </div>
                        <p className="mt-5 text-lg leading-tight font-bold" style={{ color: form.data.color }}>
                            Relatório mensal
                        </p>
                        <p className="text-[9px] text-gray-500">4 de outubro de 2026</p>
                        <div className="mt-4 space-y-1.5">
                            <div className="h-1.5 w-full rounded bg-gray-200" />
                            <div className="h-1.5 w-11/12 rounded bg-gray-200" />
                            <div className="h-1.5 w-3/4 rounded bg-gray-200" />
                        </div>
                        <div className="mt-4 overflow-hidden rounded border text-[8px]">
                            <div className="flex px-2 py-1 font-semibold" style={{ backgroundColor: `${form.data.color}22` }}>
                                <span className="flex-1">Indicador</span>
                                <span>Valor</span>
                            </div>
                            <div className="flex border-t px-2 py-1">
                                <span className="flex-1">Receita</span>
                                <span>12 450 000</span>
                            </div>
                        </div>
                        <p className="mt-auto pt-24 text-[7px] text-gray-400">{form.data.footer || brand.name}</p>
                    </div>
                </div>
            </form>
        </AppLayout>
    );
}
