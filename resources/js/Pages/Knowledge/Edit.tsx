import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import { Field } from '@/Components/Field';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import type { FolderOption } from '@/Pages/Knowledge/types';
import type { Option } from '@/types';

interface Props {
    item: { id: number; type: string; title: string; content: string; is_file: boolean; domain_id: number | null; folder_id: number | null } | null;
    defaults: { domain_id: number | null; folder_id: number | null } | null;
    domains: { id: number; name: string }[];
    folders: FolderOption[];
    types: Option[];
}

export default function KnowledgeEdit({ item, defaults, domains, folders, types }: Props) {
    const form = useForm({
        type: item?.type ?? 'article',
        title: item?.title ?? '',
        content: item?.content ?? '',
        domain_id: String(item?.domain_id ?? defaults?.domain_id ?? domains[0]?.id ?? ''),
        folder_id: String(item?.folder_id ?? defaults?.folder_id ?? ''),
    });
    const inDomain = folders.filter((f) => String(f.domain_id) === form.data.domain_id);
    const back = item ? `/knowledge/${item.id}` : '/knowledge';

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, folder_id: data.folder_id || null }));

        if (item) {
            form.put(`/knowledge/${item.id}`);
        } else {
            form.post('/knowledge');
        }
    };

    return (
        <AppLayout breadcrumbs={[{ label: 'Conhecimento', href: '/knowledge' }, ...(item ? [{ label: item.title, href: back }] : []), { label: item ? 'Editar' : 'Novo artigo' }]}>
            <Head title={item ? `Editar ${item.title}` : 'Novo artigo'} />
            <PageHeader
                title={item ? 'Editar' : 'Novo artigo'}
                description={item?.is_file ? 'Mude o título ou o sítio do ficheiro. O conteúdo vem do ficheiro.' : 'Escreva em markdown: títulos com #, listas com -, tabelas com |.'}
            />

            <form onSubmit={submit} className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-5">
                    <Field id="title" label="Título" error={form.errors.title}>
                        <Input id="title" autoFocus={!item} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} aria-invalid={!!form.errors.title} />
                    </Field>

                    {!item?.is_file && (
                        <Tabs defaultValue="write" className="gap-3">
                            <TabsList>
                                <TabsTrigger value="write">Escrever</TabsTrigger>
                                <TabsTrigger value="preview">Pré-visualizar</TabsTrigger>
                            </TabsList>
                            <TabsContent value="write">
                                <Textarea
                                    id="content"
                                    rows={22}
                                    className="font-mono text-[13px] leading-relaxed"
                                    placeholder={'# Procedimento de compras\n\n1. Pedir três cotações…'}
                                    value={form.data.content}
                                    onChange={(e) => form.setData('content', e.target.value)}
                                    aria-invalid={!!form.errors.content}
                                />
                            </TabsContent>
                            <TabsContent value="preview">
                                <div className="min-h-80 rounded-xl border bg-card px-6 py-5">
                                    <Markdown>{form.data.content || '_Nada para mostrar ainda._'}</Markdown>
                                </div>
                            </TabsContent>
                            {form.errors.content && <p className="text-sm text-destructive">{form.errors.content}</p>}
                        </Tabs>
                    )}
                </div>

                <aside className="flex flex-col gap-4 self-start rounded-xl border bg-card p-4 lg:sticky lg:top-20">
                    <Field id="domain_id" label="Domínio" error={form.errors.domain_id}>
                        <NativeSelect
                            id="domain_id"
                            value={form.data.domain_id}
                            onChange={(e) => form.setData((data) => ({ ...data, domain_id: e.target.value, folder_id: '' }))}
                        >
                            {domains.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field id="folder_id" label="Pasta" error={form.errors.folder_id}>
                        <NativeSelect id="folder_id" value={form.data.folder_id} onChange={(e) => form.setData('folder_id', e.target.value)}>
                            <option value="">Raiz do domínio</option>
                            {inDomain.map((f) => (
                                <option key={f.id} value={f.id}>
                                    {f.path}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <Field id="type" label="Tipo" error={form.errors.type}>
                        <NativeSelect id="type" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)} disabled={item?.is_file}>
                            {(item?.is_file ? [{ value: item.type, label: 'Documento' }] : types).map((t) => (
                                <option key={t.value} value={t.value}>
                                    {t.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    <div className="flex items-center justify-between gap-2 border-t pt-4">
                        <Button type="button" variant="ghost" asChild>
                            <Link href={back}>Cancelar</Link>
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {item ? 'Guardar' : 'Publicar'}
                        </Button>
                    </div>
                </aside>
            </form>
        </AppLayout>
    );
}
