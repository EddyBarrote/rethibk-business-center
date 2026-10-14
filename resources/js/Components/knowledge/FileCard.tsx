import { Link } from '@inertiajs/react';
import { Bot, Sparkles } from 'lucide-react';

import { FileIcon } from '@/Components/FileIcon';
import { StatusBadge } from '@/Components/Status';
import { ago, bytes, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { DomainDot } from '@/Pages/Knowledge/Index';
import { type KnowledgeRow, reviewState } from '@/Pages/Knowledge/types';

/**
 * How far the repository got with a file: being read, read with the help of
 * AI (scanned pages), no text at all, failed. Nothing once it reads normally.
 */
export function ReadingBadge({ item, verbose = false }: { item: KnowledgeRow; verbose?: boolean }) {
    const file = item.file;

    if (!file) {
        return null;
    }

    if (file.reading === 'pending' || file.reading === 'reading') {
        return <StatusBadge tone="running">A ler</StatusBadge>;
    }

    if (file.reading === 'failed') {
        return <StatusBadge tone="danger">Leitura falhou</StatusBadge>;
    }

    if (file.text_source === 'none') {
        return (
            <StatusBadge tone="warning" title="Sem texto: os agentes só o encontram pelo título">
                Sem texto
            </StatusBadge>
        );
    }

    if (verbose && (file.text_source === 'model' || file.text_source === 'mixed')) {
        return (
            <StatusBadge tone="idle" dot={false} title="Páginas digitalizadas lidas por IA">
                <Sparkles className="size-3" />
                lido por IA
            </StatusBadge>
        );
    }

    return null;
}

/** A file in the grid: the first page (or the image), name, pages and size. */
export function FileCard({ item, showDomain }: { item: KnowledgeRow; showDomain: boolean }) {
    const review = reviewState(item.status);

    return (
        <Link
            href={`/knowledge/${item.id}${item.page ? `?page=${item.page}` : ''}`}
            className="group flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:border-foreground/20"
        >
            <div className="relative flex aspect-[4/3] items-center justify-center overflow-hidden border-b bg-muted/50">
                {item.file?.thumbnail ? (
                    <img
                        src={item.file.thumbnail}
                        alt=""
                        loading="lazy"
                        className={cn('size-full', item.file.extension === 'pdf' ? 'object-cover object-top' : 'object-contain p-2')}
                    />
                ) : (
                    <FileIcon extension={item.file?.extension} className="size-12 rounded-xl [&>svg]:size-6" />
                )}
                <div className="absolute top-2 right-2 flex gap-1">
                    {review ? <StatusBadge tone={review.tone}>{review.label}</StatusBadge> : <ReadingBadge item={item} verbose />}
                </div>
            </div>
            <div className="flex min-w-0 flex-col gap-1 px-3 py-2.5">
                <p className="truncate text-sm font-medium group-hover:underline" title={item.title}>
                    {item.title}
                </p>
                <p className="flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                    {item.author.kind === 'agent' && <Bot className="size-3 shrink-0 text-primary" />}
                    {item.file ? (
                        <>
                            <span className="font-mono uppercase">{item.file.extension}</span>
                            {item.file.pages ? <span className="tabular-nums">· {item.file.pages} págs.</span> : null}
                            <span className="tabular-nums">· {bytes(item.file.size)}</span>
                        </>
                    ) : (
                        <span>{item.type_label}</span>
                    )}
                </p>
                <p className="flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                    {showDomain && item.domain && (
                        <>
                            <DomainDot color={item.domain.color} className="size-1.5" />
                            <span className="truncate">{item.domain.name}</span>
                            <span>·</span>
                        </>
                    )}
                    <span title={dateTime(item.updated_at)}>{ago(item.updated_at)}</span>
                </p>
            </div>
        </Link>
    );
}
