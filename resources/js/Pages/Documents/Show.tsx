import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, Download, Library, Trash2 } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { Properties, Property } from '@/Components/Blocks';
import { ExportMenu } from '@/Components/ExportMenu';
import { Field } from '@/Components/Field';
import { ExtensionTag } from '@/Components/FileIcon';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/Components/ui/alert-dialog';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { NativeSelect } from '@/Components/ui/native-select';
import AppLayout from '@/Layouts/AppLayout';
import { ago, bytes, dateTime } from '@/lib/format';
import { DocumentAuthor, type GeneratedDocument } from '@/Pages/Documents/Index';
import type { FolderOption } from '@/Pages/Knowledge/types';
import type { Option } from '@/types';

interface Props {
    document: GeneratedDocument & { source: string };
    formats: Option[];
    domains: { id: number; name: string }[];
    folders: FolderOption[];
}

export default function DocumentShow({ document, formats, domains, folders }: Props) {
    const [filing, setFiling] = useState(false);
    const form = useForm({ domain_id: String(domains[0]?.id ?? ''), folder_id: '' });
    const download = `/documents/${document.id}/download`;

    const file = (event: FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({ ...data, folder_id: data.folder_id || null }));
        form.post(`/documents/${document.id}/file`, { preserveScroll: true, onSuccess: () => setFiling(false) });
    };

    return (
        <AppLayout breadcrumbs={[{ label: 'Ficheiros', href: '/documents' }, { label: document.title }]}>
            <Head title={document.title} />
            <PageHeader
                title={document.title}
                description={<span className="font-mono text-xs">{document.filename}</span>}
                actions={
                    <>
                        <ExportMenu
                            action={`/documents/${document.id}/convert`}
                            formats={formats}
                            exclude={document.format}
                            label="Gerar noutro formato"
                        />
                        {document.knowledge_item ? (
                            <Button variant="outline" asChild>
                                <Link href={`/knowledge/${document.knowledge_item.id}`}>
                                    <Library />
                                    Ver no conhecimento
                                </Link>
                            </Button>
                        ) : (
                            <Button variant="outline" onClick={() => setFiling(true)} disabled={domains.length === 0}>
                                <Archive />
                                Arquivar no conhecimento
                            </Button>
                        )}
                        <Button asChild>
                            <a href={download}>
                                <Download />
                                Descarregar
                            </a>
                        </Button>
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button variant="ghost" size="icon" aria-label="Apagar" className="hover:text-status-danger">
                                    <Trash2 />
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Apagar {document.filename}?</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        O ficheiro deixa de estar disponível. Uma cópia arquivada na base de conhecimento continua lá.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                                    <AlertDialogAction onClick={() => router.delete(`/documents/${document.id}`)}>Apagar</AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </>
                }
            />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="min-w-0">
                    {document.format === 'pdf' ? (
                        <iframe title={document.title} src={`${download}?inline=1`} className="h-[78vh] w-full rounded-xl border bg-card" />
                    ) : (
                        <article className="rounded-xl border bg-card">
                            <div className="border-b px-5 py-2.5 text-xs text-muted-foreground">
                                Pré-visualização do conteúdo. O ficheiro {document.format_label} tem a marca e o layout da organização.
                            </div>
                            <div className="px-6 py-5">
                                <Markdown>{document.source}</Markdown>
                            </div>
                        </article>
                    )}
                </div>

                <Properties className="lg:sticky lg:top-20 lg:self-start">
                    <Property label="Formato">
                        <span className="flex items-center gap-2">
                            <ExtensionTag extension={document.format} />
                            {document.format_label}
                        </span>
                    </Property>
                    {(document.format === 'docx' || document.format === 'pdf') && <Property label="Modelo">{document.template_label}</Property>}
                    <Property label="Tamanho">
                        <span className="font-mono text-xs tabular-nums">{bytes(document.size)}</span>
                    </Property>
                    <Property label="Gerado por">
                        <DocumentAuthor document={document} />
                    </Property>
                    <Property label="Gerado">
                        <span title={dateTime(document.created_at)}>{ago(document.created_at)}</span>
                    </Property>
                    <Property label="Conhecimento">
                        {document.knowledge_item ? (
                            <Link href={`/knowledge/${document.knowledge_item.id}`} className="text-primary hover:underline">
                                Arquivado
                            </Link>
                        ) : (
                            <span className="text-muted-foreground">Não arquivado</span>
                        )}
                    </Property>
                </Properties>
            </div>

            <Dialog open={filing} onOpenChange={setFiling}>
                <DialogContent>
                    <form onSubmit={file} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>Arquivar na base de conhecimento</DialogTitle>
                            <DialogDescription>Fica pesquisável por pessoas e agentes com acesso ao domínio.</DialogDescription>
                        </DialogHeader>
                        <Field id="file-domain" label="Domínio" error={form.errors.domain_id}>
                            <NativeSelect
                                id="file-domain"
                                value={form.data.domain_id}
                                onChange={(e) => form.setData((d) => ({ ...d, domain_id: e.target.value, folder_id: '' }))}
                            >
                                {domains.map((d) => (
                                    <option key={d.id} value={d.id}>
                                        {d.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field id="file-folder" label="Pasta" error={form.errors.folder_id}>
                            <NativeSelect id="file-folder" value={form.data.folder_id} onChange={(e) => form.setData('folder_id', e.target.value)}>
                                <option value="">Raiz do domínio</option>
                                {folders
                                    .filter((f) => String(f.domain_id) === form.data.domain_id)
                                    .map((f) => (
                                        <option key={f.id} value={f.id}>
                                            {f.path}
                                        </option>
                                    ))}
                            </NativeSelect>
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setFiling(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Arquivar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
