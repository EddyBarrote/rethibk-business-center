import { Head, Link, useForm } from '@inertiajs/react';
import { Bot, Download, FilePlus2, FolderOpen } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EntityRow, ListPanel } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { ExtensionTag, FileIcon } from '@/Components/FileIcon';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { ago, bytes, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, Paginated } from '@/types';

export interface GeneratedDocument {
    id: number;
    title: string;
    format: 'docx' | 'pptx' | 'xlsx' | 'pdf';
    format_label: string;
    template: string;
    template_label: string | null;
    filename: string;
    size: number;
    agent: { id: number; name: string } | null;
    creator: string | null;
    knowledge_item: { id: number } | null;
    created_at: string;
}

interface Props {
    documents: Paginated<GeneratedDocument>;
    filter: string | null;
    formats: Option[];
    templates: Option[];
}

const placeholder = `# Proposta de manutenção

## Âmbito

- Visita mensal às instalações
- Relatório com fotografias

## Valores

| Serviço | Valor (MZN) |
|---|---|
| Manutenção mensal | 45 000,00 |`;

export function DocumentAuthor({ document }: { document: GeneratedDocument }) {
    return document.agent ? (
        <span className="flex items-center gap-1">
            <Bot className="size-3.5 text-primary" />
            {document.agent.name}
        </span>
    ) : (
        <span>{document.creator ?? '—'}</span>
    );
}

export default function DocumentsIndex({ documents, filter, formats, templates }: Props) {
    const [creating, setCreating] = useState(false);
    const form = useForm({ title: '', subtitle: '', format: 'docx', template: 'documento', content: '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/documents');
    };

    const chip = (value: string | null, label: string) => (
        <Link
            key={label}
            href={value ? `/documents?format=${value}` : '/documents'}
            preserveState
            className={cn(
                'rounded-full border px-3 py-1 text-xs transition-colors hover:bg-accent/60',
                filter === value ? 'border-foreground/20 bg-accent font-medium' : 'text-muted-foreground',
            )}
        >
            {label}
        </Link>
    );

    return (
        <AppLayout>
            <Head title="Ficheiros" />
            <PageHeader
                title="Ficheiros"
                description="Documentos Word, apresentações, folhas de cálculo e PDFs gerados pelos agentes ou por si, com a marca da organização."
                actions={
                    <Button onClick={() => setCreating(true)}>
                        <FilePlus2 />
                        Novo ficheiro
                    </Button>
                }
            />

            <div className="flex flex-wrap gap-2">
                {chip(null, 'Todos')}
                {formats.map((f) => chip(f.value, f.label))}
            </div>

            {documents.data.length === 0 ? (
                <EmptyState
                    icon={FolderOpen}
                    title="Ainda não há ficheiros"
                    description="Peça a um agente “prepara uma apresentação sobre…” ou crie um ficheiro a partir de texto em markdown."
                    action={
                        <Button variant="outline" size="sm" onClick={() => setCreating(true)}>
                            Novo ficheiro
                        </Button>
                    }
                />
            ) : (
                <ListPanel>
                    {documents.data.map((d) => (
                        <EntityRow
                            key={d.id}
                            href={`/documents/${d.id}`}
                            leading={<FileIcon extension={d.format} />}
                            title={d.title}
                            subtitle={<span className="font-mono">{d.filename}</span>}
                            meta={
                                <>
                                    <ExtensionTag extension={d.format} />
                                    {d.knowledge_item && <span title="Arquivado na base de conhecimento">no conhecimento</span>}
                                    <DocumentAuthor document={d} />
                                    <span className="w-16 text-right font-mono tabular-nums">{bytes(d.size)}</span>
                                    <span className="w-20 text-right" title={dateTime(d.created_at)}>
                                        {ago(d.created_at)}
                                    </span>
                                </>
                            }
                            trailing={
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label="Descarregar"
                                    onClick={(e) => {
                                        e.preventDefault();
                                        window.location.href = `/documents/${d.id}/download`;
                                    }}
                                >
                                    <Download />
                                </Button>
                            }
                        />
                    ))}
                </ListPanel>
            )}
            <Pagination page={documents} />

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>Novo ficheiro</DialogTitle>
                            <DialogDescription>
                                Escreva em markdown. Em apresentações, cada título ## é um diapositivo; em Excel, cada tabela é uma folha.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="doc-title" label="Título" error={form.errors.title}>
                                <Input id="doc-title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                            </Field>
                            <Field id="doc-subtitle" label="Subtítulo" error={form.errors.subtitle}>
                                <Input
                                    id="doc-subtitle"
                                    value={form.data.subtitle}
                                    onChange={(e) => form.setData('subtitle', e.target.value)}
                                    placeholder="Opcional"
                                />
                            </Field>
                            <Field id="doc-format" label="Formato" error={form.errors.format}>
                                <NativeSelect id="doc-format" value={form.data.format} onChange={(e) => form.setData('format', e.target.value)}>
                                    {formats.map((f) => (
                                        <option key={f.value} value={f.value}>
                                            {f.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field id="doc-template" label="Modelo" error={form.errors.template} hint="Word e PDF.">
                                <NativeSelect
                                    id="doc-template"
                                    value={form.data.template}
                                    onChange={(e) => form.setData('template', e.target.value)}
                                    disabled={form.data.format === 'xlsx' || form.data.format === 'pptx'}
                                >
                                    {templates.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </div>
                        <Field id="doc-content" label="Conteúdo" error={form.errors.content}>
                            <Textarea
                                id="doc-content"
                                rows={12}
                                className="font-mono text-[13px]"
                                placeholder={placeholder}
                                value={form.data.content}
                                onChange={(e) => form.setData('content', e.target.value)}
                            />
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'A gerar…' : 'Gerar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
