import { Head, Link, router } from '@inertiajs/react';
import { Bot, Check, Download, Pencil, Trash2, X } from 'lucide-react';

import { Properties, Property } from '@/Components/Blocks';
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
import AppLayout from '@/Layouts/AppLayout';
import { ago, bytes, dateTime } from '@/lib/format';
import { DomainDot } from '@/Pages/Knowledge/Index';
import { indexState, type KnowledgeRow, reviewState } from '@/Pages/Knowledge/types';

interface Item extends KnowledgeRow {
    content: string;
    preview: 'markdown' | 'pdf' | 'image' | 'text';
    reviewed_by: string | null;
    reviewed_at: string | null;
    folder_trail: { id: number; name: string }[];
}

interface Props {
    item: Item;
    can: { edit: boolean; review: boolean; delete: boolean };
}

export default function KnowledgeShow({ item, can }: Props) {
    const review = reviewState(item.status);
    const index = indexState(item.embedding_status);
    const fileUrl = `/knowledge/${item.id}/file`;
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
                <div className="min-w-0">
                    {item.preview === 'pdf' && <PdfPreview src={fileUrl} title={item.title} />}
                    {item.preview === 'image' && (
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
