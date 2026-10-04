import { Head, Link, router, useForm } from '@inertiajs/react';
import { Library, Plus, Search } from 'lucide-react';
import { type FormEvent, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { PageHeader } from '@/Components/PageHeader';
import { Badge } from '@/Components/ui/badge';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { date } from '@/lib/format';
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

export default function KnowledgeIndex({ items, filters, types, semantic }: Props) {
    const [query, setQuery] = useState(filters.q);
    const [adding, setAdding] = useState(false);
    const form = useForm<{ type: string; title: string; content: string; file: File | null }>({ type: 'meeting_brief', title: '', content: '', file: null });

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
                    <Button onClick={() => setAdding(!adding)} variant={adding ? 'outline' : 'default'}>
                        <Plus />
                        Adicionar
                    </Button>
                }
            />

            {adding && (
                <Card>
                    <form onSubmit={submit}>
                        <CardHeader>
                            <CardTitle>Novo item</CardTitle>
                            <CardDescription>Escreva o texto ou carregue um documento (PDF, Word, Excel, texto).</CardDescription>
                        </CardHeader>
                        <CardContent className="mt-4 grid gap-4">
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
                                <Input id="file" type="file" accept=".pdf,.docx,.xlsx,.txt,.md,.csv,.html" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} />
                            </Field>
                        </CardContent>
                        <CardFooter className="mt-4 justify-end">
                            <Button type="submit" disabled={form.processing}>
                                Guardar na memória
                            </Button>
                        </CardFooter>
                    </form>
                </Card>
            )}

            <form onSubmit={search} className="flex gap-2">
                <Input placeholder="Pesquisar na memória…" value={query} onChange={(e) => setQuery(e.target.value)} />
                <Button type="submit" variant="outline">
                    <Search />
                    Pesquisar
                </Button>
            </form>

            {items.length === 0 ? (
                <EmptyState icon={Library} title={filters.q ? 'Sem resultados' : 'Memória vazia'} description={filters.q ? 'Tente outras palavras.' : 'Adicione decisões, resumos de reuniões e documentos.'} />
            ) : (
                <div className="grid gap-3">
                    {items.map((item) => (
                        <Link key={item.id} href={`/knowledge/${item.id}`}>
                            <Card className="transition-colors hover:border-primary/40">
                                <CardHeader>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Badge variant="secondary">{item.type_label}</Badge>
                                        {item.is_external && <Badge variant="outline">externo</Badge>}
                                        <span className="text-xs text-muted-foreground">{date(item.created_at)}</span>
                                        {item.score !== null && item.score > 0 && <span className="text-xs text-muted-foreground">relevância {Math.round(item.score * 100)}%</span>}
                                    </div>
                                    <CardTitle className="mt-1">{item.title}</CardTitle>
                                </CardHeader>
                                <CardContent className="mt-2 line-clamp-3 text-sm text-muted-foreground">{item.excerpt}</CardContent>
                            </Card>
                        </Link>
                    ))}
                </div>
            )}
        </AppLayout>
    );
}
