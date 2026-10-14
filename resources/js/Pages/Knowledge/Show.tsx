import { Head, Link, router } from '@inertiajs/react';
import { Bot, Check, CircleAlert, Download, History, LoaderCircle, Pencil, RotateCw, Sparkles, Trash2, Upload, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { EntityRow, ListPanel, Properties, Property, Section } from '@/Components/Blocks';
import { ExtensionTag } from '@/Components/FileIcon';
import { Markdown } from '@/Components/Markdown';
import { PageHeader } from '@/Components/PageHeader';
import { PdfPreview } from '@/Components/PdfPreview';
import { StatusBadge } from '@/Components/Status';
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
import { Progress } from '@/Components/ui/progress';
import { Tabs, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import AppLayout from '@/Layouts/AppLayout';
import { type AttachmentLimits, uploadAttachment } from '@/lib/attachments';
import { ago, bytes, dateTime } from '@/lib/format';
import { DomainDot } from '@/Pages/Knowledge/Index';
import { indexState, type KnowledgeRow, reviewState } from '@/Pages/Knowledge/types';

interface Item extends KnowledgeRow {
    content: string;
    preview: 'markdown' | 'pdf' | 'image' | 'text';
    reviewed_by: string | null;
    reviewed_at: string | null;
    folder_trail: { id: number; name: string }[];
    reading_note: string | null;
    text_source: 'text' | 'model' | 'mixed' | 'none' | null;
    page_sources: number[];
    versions: { id: number; version: number; filename: string; size: number | null; created_at: string }[];
}

interface Props {
    item: Item;
    page: number | null;
    upload: AttachmentLimits;
    can: { edit: boolean; review: boolean; delete: boolean };
}

const TEXT_SOURCE: Record<string, string> = {
    text: 'Texto do ficheiro',
    model: 'Lido por IA (digitalizado)',
    mixed: 'Texto do ficheiro e páginas lidas por IA',
    none: 'Sem texto legível',
};

export default function KnowledgeShow({ item, page, upload, can }: Props) {
    const review = reviewState(item.status);
    const index = indexState(item.embedding_status);
    const fileUrl = `/knowledge/${item.id}/file`;
    const reading = item.file?.reading === 'pending' || item.file?.reading === 'reading';
    const visual = item.preview === 'pdf' || item.preview === 'image';
    const [view, setView] = useState<'original' | 'text'>('original');
    const [sending, setSending] = useState<number | null>(null);
    const [sendError, setSendError] = useState<string | null>(null);
    const versionInput = useRef<HTMLInputElement>(null);

    // While the file is read on the queue, look again every few seconds.
    useEffect(() => {
        if (!reading) {
            return;
        }

        const timer = window.setInterval(() => router.reload({ only: ['item'] }), 4000);

        return () => window.clearInterval(timer);
    }, [reading]);

    const sendVersion = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setSendError(null);
        setSending(0);

        try {
            const { id } = await uploadAttachment(file, {}, upload.chunk_bytes, setSending, new AbortController().signal, 'repository');
            router.post(`/knowledge/${item.id}/version`, { upload_id: id }, { preserveScroll: true, onFinish: () => setSending(null) });
        } catch (e) {
            setSending(null);
            setSendError((e as Error).message);
        }
    };
    const crumbs = [
        { label: 'Conhecimento', href: '/knowledge' },
        ...(item.domain ? [{ label: item.domain.name, href: `/knowledge?domain=${item.domain.slug}` }] : []),
        ...item.folder_trail.map((f) => ({ label: f.name, href: `/knowledge?domain=${item.domain?.slug}&folder=${f.id}` })),
        { label: item.title },
    ];

    return (
        <AppLayout breadcrumbs={crumbs}>
            <Head title={item.title} />
            <PageHeader
                title={item.title}
                description={
                    <span className="flex flex-wrap items-center gap-x-1.5">
                        <span>{item.type_label}</span>
                        <span>·</span>
                        {item.author.kind === 'agent' && <Bot className="size-3.5 text-primary" />}
                        <span>{item.author.name}</span>
                        <span>·</span>
                        <span title={dateTime(item.updated_at)}>{ago(item.updated_at)}</span>
                    </span>
                }
                actions={
                    <>
                        {can.review && (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => router.post(`/knowledge/${item.id}/review`, { decision: 'reject' }, { preserveScroll: true })}
                                >
                                    <X />
                                    Rejeitar
                                </Button>
                                <Button
                                    onClick={() => router.post(`/knowledge/${item.id}/review`, { decision: 'approve' }, { preserveScroll: true })}
                                >
                                    <Check />
                                    Aprovar
                                </Button>
                            </>
                        )}
                        {item.file && (
                            <Button variant="outline" asChild>
                                <a href={fileUrl}>
                                    <Download />
                                    Descarregar
                                </a>
                            </Button>
                        )}
                        {can.edit && item.file && (
                            <>
                                <Button variant="outline" onClick={() => versionInput.current?.click()} disabled={sending !== null}>
                                    <Upload />
                                    Nova versão
                                </Button>
                                <input
                                    ref={versionInput}
                                    type="file"
                                    className="hidden"
                                    accept={upload.extensions.map((e) => `.${e}`).join(',')}
                                    onChange={(e) => {
                                        void sendVersion(e.target.files?.[0]);
                                        e.target.value = '';
                                    }}
                                />
                            </>
                        )}
                        {can.edit && (
                            <Button variant="outline" asChild>
                                <Link href={`/knowledge/${item.id}/edit`}>
                                    <Pencil />
                                    Editar
                                </Link>
                            </Button>
                        )}
                        {can.delete && (
                            <AlertDialog>
                                <AlertDialogTrigger asChild>
                                    <Button variant="ghost" size="icon" aria-label="Apagar" className="hover:text-status-danger">
                                        <Trash2 />
                                    </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <AlertDialogHeader>
                                        <AlertDialogTitle>Apagar “{item.title}”?</AlertDialogTitle>
                                        <AlertDialogDescription>
                                            Deixa de aparecer nas pesquisas de pessoas e agentes{item.file ? ' e o ficheiro é removido' : ''}. Não
                                            pode ser desfeito.
                                        </AlertDialogDescription>
                                    </AlertDialogHeader>
                                    <AlertDialogFooter>
                                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                                        <AlertDialogAction onClick={() => router.delete(`/knowledge/${item.id}`)}>Apagar</AlertDialogAction>
                                    </AlertDialogFooter>
                                </AlertDialogContent>
                            </AlertDialog>
                        )}
                    </>
                }
            />

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <div className="flex min-w-0 flex-col gap-4">
                    {sending !== null && (
                        <div className="flex flex-col gap-1.5 rounded-xl border bg-card px-4 py-3">
                            <p className="text-sm">A enviar a nova versão…</p>
                            <Progress value={sending * 100} />
                        </div>
                    )}
                    {sendError && <p className="text-sm text-destructive">{sendError}</p>}
                    {reading && (
                        <div className="flex items-center gap-3 rounded-xl border bg-card px-4 py-3 text-sm">
                            <LoaderCircle className="size-4 shrink-0 animate-spin text-primary" />
                            <span>
                                A ler o documento{item.file?.pages ? ` (${item.file.pages} páginas)` : ''}. As páginas digitalizadas são lidas por IA; os
                                agentes encontram-no na pesquisa quando acabar.
                            </span>
                        </div>
                    )}
                    {!reading && (item.reading_note || item.file?.reading === 'failed') && (
                        <div className="flex items-start gap-3 rounded-xl border border-status-warning/30 bg-status-warning/5 px-4 py-3 text-sm">
                            <CircleAlert className="mt-0.5 size-4 shrink-0 text-status-warning" />
                            <span className="flex-1">{item.reading_note ?? 'A leitura deste ficheiro falhou.'}</span>
                            {can.edit && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => router.post(`/knowledge/${item.id}/reread`, {}, { preserveScroll: true })}
                                >
                                    <RotateCw />
                                    Ler outra vez
                                </Button>
                            )}
                        </div>
                    )}
                    {visual && (
                        <Tabs value={view} onValueChange={(value) => setView(value as 'original' | 'text')}>
                            <TabsList>
                                <TabsTrigger value="original">Original</TabsTrigger>
                                <TabsTrigger value="text">O que os agentes lêem</TabsTrigger>
                            </TabsList>
                        </Tabs>
                    )}
                    {visual && view === 'text' && (
                        <article className="rounded-xl border bg-card">
                            <div className="border-b px-5 py-2.5 text-xs text-muted-foreground">
                                {item.page_sources.length > 0
                                    ? `Páginas ${item.page_sources.join(', ')} lidas por IA, por serem digitalizadas; o resto é o texto do ficheiro.`
                                    : 'O texto tirado do ficheiro, página a página. É o que a pesquisa e os agentes usam.'}
                            </div>
                            <div className="max-h-[70vh] overflow-auto px-5 py-4 text-sm leading-relaxed whitespace-pre-wrap">
                                {item.content || <span className="text-muted-foreground">{reading ? 'Ainda a ler…' : 'Sem texto legível.'}</span>}
                            </div>
                        </article>
                    )}
                    {item.preview === 'pdf' && view === 'original' && <PdfPreview src={fileUrl} title={item.title} page={page} />}
                    {item.preview === 'image' && view === 'original' && (
                        <div className="rounded-xl border bg-card p-3">
                            <img src={`${fileUrl}?inline=1`} alt={item.title} className="mx-auto max-h-[78vh] rounded-lg" />
                        </div>
                    )}
                    {item.preview === 'markdown' && (
                        <article className="rounded-xl border bg-card px-6 py-5">
                            <Markdown>{item.content || '_Sem conteúdo._'}</Markdown>
                        </article>
                    )}
                    {item.preview === 'text' && (
                        <article className="rounded-xl border bg-card">
                            <div className="border-b px-5 py-2.5 text-xs text-muted-foreground">
                                Texto extraído de <span className="font-mono">{item.file?.name}</span>; para ver o formato original, descarregue o
                                ficheiro.
                            </div>
                            <div className="max-h-[70vh] overflow-auto px-5 py-4 text-sm leading-relaxed whitespace-pre-wrap">
                                {item.content || (
                                    <span className="text-muted-foreground">
                                        Não foi possível extrair texto deste ficheiro; os agentes só o encontram pelo título.
                                    </span>
                                )}
                            </div>
                        </article>
                    )}
                    {item.versions.length > 0 && (
                        <Section title="Versões anteriores">
                            <ListPanel>
                                {item.versions.map((v) => (
                                    <EntityRow
                                        key={v.id}
                                        leading={
                                            <span className="inline-flex size-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                <History className="size-3.5" />
                                            </span>
                                        }
                                        title={`Versão ${v.version} · ${v.filename}`}
                                        meta={
                                            <>
                                                <span className="font-mono text-xs tabular-nums">{bytes(v.size)}</span>
                                                <span title={dateTime(v.created_at)}>{ago(v.created_at)}</span>
                                            </>
                                        }
                                        trailing={
                                            <Button variant="ghost" size="icon" asChild aria-label="Descarregar">
                                                <a href={`/knowledge/${item.id}/versions/${v.id}/file`}>
                                                    <Download />
                                                </a>
                                            </Button>
                                        }
                                    />
                                ))}
                            </ListPanel>
                        </Section>
                    )}
                </div>

                <Properties className="lg:sticky lg:top-20 lg:self-start">
                    <Property label="Estado">
                        {review ? <StatusBadge tone={review.tone}>{review.label}</StatusBadge> : <StatusBadge tone="success">Publicado</StatusBadge>}
                    </Property>
                    <Property label="Domínio">
                        {item.domain && (
                            <Link href={`/knowledge?domain=${item.domain.slug}`} className="flex items-center gap-1.5 hover:underline">
                                <DomainDot color={item.domain.color} />
                                {item.domain.name}
                            </Link>
                        )}
                    </Property>
                    <Property label="Pasta">{item.folder_trail.length > 0 ? item.folder_trail.map((f) => f.name).join(' / ') : null}</Property>
                    <Property label="Tipo">{item.type_label}</Property>
                    <Property label="Escrito por">
                        <span className="flex items-center gap-1.5">
                            {item.author.kind === 'agent' && <Bot className="size-3.5 text-primary" />}
                            {item.author.kind === 'agent' && item.author.id ? (
                                <Link href={`/agents/${item.author.id}`} className="hover:underline">
                                    {item.author.name}
                                </Link>
                            ) : (
                                item.author.name
                            )}
                        </span>
                    </Property>
                    {item.file && (
                        <Property label="Ficheiro">
                            <span className="flex items-center gap-2">
                                <ExtensionTag extension={item.file.extension} />
                                <span className="font-mono text-xs tabular-nums">{bytes(item.file.size)}</span>
                            </span>
                        </Property>
                    )}
                    {item.file?.pages ? <Property label="Páginas">{item.file.pages}</Property> : null}
                    {item.file && item.file.version > 1 && <Property label="Versão">{item.file.version}</Property>}
                    {item.file && item.text_source && (
                        <Property label="Texto">
                            <span className="flex items-center gap-1.5">
                                {(item.text_source === 'model' || item.text_source === 'mixed') && <Sparkles className="size-3.5 text-primary" />}
                                {TEXT_SOURCE[item.text_source]}
                            </span>
                        </Property>
                    )}
                    {item.is_external && (
                        <Property label="Origem">
                            <StatusBadge tone="warning" dot={false} title="Conteúdo vindo de fora da organização">
                                conteúdo externo
                            </StatusBadge>
                        </Property>
                    )}
                    {item.reviewed_by && (
                        <Property label={item.status === 'rejected' ? 'Rejeitado por' : 'Aprovado por'}>
                            {item.reviewed_by}{' '}
                            <span className="text-muted-foreground" title={dateTime(item.reviewed_at)}>
                                · {ago(item.reviewed_at)}
                            </span>
                        </Property>
                    )}
                    <Property label="Criado">
                        <span title={dateTime(item.created_at)}>{ago(item.created_at)}</span>
                    </Property>
                    {index && (
                        <Property label="Índice">
                            <StatusBadge tone={index.tone}>{index.label}</StatusBadge>
                        </Property>
                    )}
                    <Property label="ID">
                        <span className="font-mono text-xs">{item.id}</span>
                    </Property>
                    {item.status === 'pending_review' && (
                        <p className="mt-2 border-t pt-3 text-xs text-muted-foreground">
                            Escrito por um agente com autonomia abaixo de N3: as pessoas já o vêem, os agentes só depois de aprovado.
                        </p>
                    )}
                </Properties>
            </div>
        </AppLayout>
    );
}
