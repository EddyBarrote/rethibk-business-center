import { Head, router, useForm } from '@inertiajs/react';
import { FileText, Globe, Library, Plus, Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { ago, date } from '@/lib/format';
import type { Option } from '@/types';

interface Item {
    id: number;
    type: string;
    type_label: string;
    title: string;
    is_external: boolean;
    embedding_status: string;
    created_at: string;
    excerpt: string;
    score: number | null;
}

interface Props {
    items: Item[];
    filters: { q: string; type: string | null };
    types: Option[];
    semantic: boolean;
}

/** Indexing state, shown only when it is not the normal "done". */
const embeddingState = (status: string): { tone: Tone; label: string } | null =>
    ({
        pending: { tone: 'running' as Tone, label: 'A indexar' },
        failed: { tone: 'danger' as Tone, label: 'Indexação falhou' },
        skipped: { tone: 'idle' as Tone, label: 'Não indexado' },
    })[status] ?? null;

export default function KnowledgeIndex({ items, filters, types, semantic }: Props) {
    const [query, setQuery] = useState(filters.q);
    const [adding, setAdding] = useState(false);
    const form = useForm<{ type: string; title: string; content: string; file: File | null }>({
        type: 'meeting_brief',
        title: '',
        content: '',
        file: null,
    });

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get('/knowledge', query ? { q: query } : {}, { preserveState: true });
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/knowledge', { forceFormData: true });
    };

    return (
        <AppLayout>
            <Head title="Memória" />
            <PageHeader
                title="Memória"
                description={`Decisões, resumos de reuniões e documentos que os agentes consultam. Pesquisa ${semantic ? 'por significado' : 'por palavras'}.`}
                actions={
                    !adding && (
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            Adicionar
                        </Button>
                    )
                }
            />

            {adding && (
                <form onSubmit={submit} className="flex flex-col gap-5 rounded-xl border bg-card p-5">
                    <div>
                        <h2 className="text-sm font-semibold">Novo item</h2>
                        <p className="text-xs text-muted-foreground">Escreva o texto ou carregue um documento (PDF, Word, Excel, texto).</p>
                    </div>
                    <div className="grid gap-4 sm:grid-cols-[1fr_2fr]">
                        <Field id="type" label="Tipo" error={form.errors.type}>
                            <NativeSelect id="type" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </NativeSelect>
                        </Field>
                        <Field id="title" label="Título" error={form.errors.title}>
                            <Input id="title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                        </Field>
                    </div>
                    <Field id="content" label="Texto" error={form.errors.content}>
                        <Textarea id="content" rows={6} value={form.data.content} onChange={(e) => form.setData('content', e.target.value)} />
                    </Field>
                    <Field id="file" label="Documento" error={form.errors.file}>
                        <Input
                            id="file"
                            type="file"
                            accept=".pdf,.docx,.xlsx,.txt,.md,.csv,.html"
                            onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                        />
                    </Field>
                    <div className="flex items-center justify-between gap-2 border-t pt-4">
                        <Button type="button" variant="ghost" onClick={() => setAdding(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            Guardar na memória
                        </Button>
                    </div>
                </form>
            )}

            <form onSubmit={search} className="flex gap-2">
                <div className="relative flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input className="pl-9" placeholder="Pesquisar na memória…" value={query} onChange={(e) => setQuery(e.target.value)} />
                </div>
                <Button type="submit" variant="outline">
                    Pesquisar
                </Button>
            </form>

            {items.length === 0 ? (
                <EmptyState
                    icon={Library}
                    title={filters.q ? 'Sem resultados' : 'Memória vazia'}
                    description={
                        filters.q
                            ? 'Tente outras palavras ou uma pergunta mais curta.'
                            : 'Comece por adicionar uma decisão, o resumo de uma reunião ou um documento que os agentes devam conhecer.'
                    }
                />
            ) : (
                <Section
                    title={filters.q ? `Resultados para “${filters.q}”` : 'Itens recentes'}
                    action={<span className="text-xs text-muted-foreground tabular-nums">{items.length}</span>}
                >
                    <ListPanel>
                        {items.map((item) => {
                            const state = embeddingState(item.embedding_status);

                            return (
                                <EntityRow
                                    key={item.id}
                                    href={`/knowledge/${item.id}`}
                                    className="py-3"
                                    leading={
                                        <span className="inline-flex size-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                            {item.is_external ? <Globe className="size-3.5" /> : <FileText className="size-3.5" />}
                                        </span>
                                    }
                                    title={item.title}
                                    subtitle={item.excerpt}
                                    meta={
                                        <>
                                            {item.score !== null && item.score > 0 && (
                                                <span className="font-mono tabular-nums" title="Relevância">
                                                    {Math.round(item.score * 100)}%
                                                </span>
                                            )}
                                            <span>{item.type_label}</span>
                                            {item.is_external && <span>externo</span>}
                                            <span className="w-20 text-right" title={date(item.created_at)}>
                                                {ago(item.created_at)}
                                            </span>
                                        </>
                                    }
                                    trailing={state && <StatusBadge tone={state.tone}>{state.label}</StatusBadge>}
                                />
                            );
                        })}
                    </ListPanel>
                </Section>
            )}
        </AppLayout>
    );
}
