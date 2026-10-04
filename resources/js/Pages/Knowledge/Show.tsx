import { Head } from '@inertiajs/react';

import { Properties, Property } from '@/Components/Blocks';
import { StatusBadge, type Tone } from '@/Components/Status';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';

interface Item {
    id: number;
    type_label: string;
    title: string;
    content: string;
    is_external: boolean;
    embedding_status?: string;
    author?: string | null;
    created_at: string;
}

const embedding: Record<string, { tone: Tone; label: string }> = {
    pending: { tone: 'running', label: 'A indexar' },
    done: { tone: 'success', label: 'Indexado' },
    failed: { tone: 'danger', label: 'Indexação falhou' },
    skipped: { tone: 'idle', label: 'Não indexado' },
};

const authors: Record<string, string> = { user: 'Pessoa', agent: 'Agente', system: 'Sistema' };
const authorLabel = (type: string) => authors[(type.split('\\').pop() ?? type).toLowerCase()] ?? type;

export default function KnowledgeShow({ item }: { item: Item }) {
    const state = item.embedding_status ? embedding[item.embedding_status] : undefined;

    return (
        <AppLayout breadcrumbs={[{ label: 'Memória', href: '/knowledge' }, { label: item.title }]}>
            <Head title={item.title} />

            <div className="space-y-1">
                <h1 className="text-xl font-semibold tracking-tight">{item.title}</h1>
                <p className="text-sm text-muted-foreground">
                    {item.type_label} · <span title={dateTime(item.created_at)}>{ago(item.created_at)}</span>
                </p>
            </div>

            <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                <article className="min-w-0 rounded-xl border bg-card p-5 text-sm leading-relaxed whitespace-pre-wrap">{item.content}</article>

                <Properties className="self-start">
                    <Property label="Tipo">{item.type_label}</Property>
                    <Property label="Origem">
                        {item.is_external ? (
                            <StatusBadge tone="warning" dot={false} title="Conteúdo vindo de fora da organização">
                                conteúdo externo
                            </StatusBadge>
                        ) : (
                            'Interna'
                        )}
                    </Property>
                    {item.author && <Property label="Criado por">{authorLabel(item.author)}</Property>}
                    <Property label="Criado em">
                        <span className="tabular-nums">{dateTime(item.created_at)}</span>
                    </Property>
                    {state && (
                        <Property label="Índice">
                            <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
                        </Property>
                    )}
                    <Property label="ID">
                        <span className="font-mono text-xs">{item.id}</span>
                    </Property>
                </Properties>
            </div>
        </AppLayout>
    );
}
