import { Head, Link, router, useForm } from '@inertiajs/react';
import { Archive, Download, Library, Trash2 } from 'lucide-react';
import { type FormEvent, type ReactNode, useState } from 'react';

import { ExportMenu } from '@/Components/ExportMenu';
import { Field } from '@/Components/Field';
import { ExtensionTag } from '@/Components/FileIcon';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { PdfPreview } from '@/Components/PdfPreview';
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
    document: GeneratedDocument & { source: string; report: { id: number; title: string } | null };
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
        <AppLayout breadcrumbs={[{ label: 'Documentos', href: '/documents' }, { label: document.title }]}>
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

            {/* The file takes the page; its details sit beside it as plain text, without a box. */}
            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_16rem] xl:gap-10">
                <div className="min-w-0">
                    {document.format === 'pdf' ? (
                        <PdfPreview src={download} title={document.title} className="h-[calc(100dvh-12rem)] min-h-[32rem] border-0 bg-muted/40" />
                    ) : (
                        <article className="flex flex-col gap-3">
                            <p className="text-xs text-muted-foreground">
                                Pré-visualização do conteúdo. O ficheiro {document.format_label} tem a marca e o layout da organização.
                            </p>
                            <Markdown>{document.source}</Markdown>
                        </article>
                    )}
                </div>

                <aside aria-label="Detalhes" className="flex flex-col gap-5 text-sm lg:sticky lg:top-20 lg:self-start">
                    <Detail label="Feito por">
                        <DocumentAuthor document={document} />
                    </Detail>
                    <Detail label="Formato">
                        <span className="flex items-center gap-2">
                            <ExtensionTag extension={document.format} />
                            {document.format_label}
                            {(document.format === 'docx' || document.format === 'pdf') && document.template_label && (
                                <span className="text-muted-foreground">· {document.template_label}</span>
                            )}
                        </span>
                    </Detail>
                    <Detail label="Tamanho">
                        <span className="tabular-nums">{bytes(document.size ?? 0)}</span>
                    </Detail>
                    <Detail label="Criado">
                        <span title={dateTime(document.created_at)}>
                            {dateTime(document.created_at)} <span className="text-muted-foreground">({ago(document.created_at)})</span>
                        </span>
                    </Detail>
                    {document.report && (
                        <Detail label="Exportado do relatório">
                            <Link href={`/reports/${document.report.id}`} className="text-primary hover:underline">
                                {document.report.title}
                            </Link>
                        </Detail>
                    )}
                    <Detail label="Pasta">
                        {document.folder ? (
                            <Link href={`/documents?pasta=${document.folder.id}`} className="hover:underline">
                                {document.folder.name}
                            </Link>
                        ) : (
                            <span className="text-muted-foreground">Sem pasta</span>
                        )}
                    </Detail>
                    <Detail label="Conhecimento">
                        {document.knowledge_item ? (
                            <Link href={`/knowledge/${document.knowledge_item.id}`} className="text-primary hover:underline">
                                Arquivado
                            </Link>
                        ) : (
                            <span className="text-muted-foreground">Não arquivado</span>
                        )}
                    </Detail>
                </aside>
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

function Detail({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="flex min-w-0 flex-col gap-1">
            <span className="text-xs text-muted-foreground">{label}</span>
            <div className="min-w-0">{children}</div>
        </div>
    );
}
