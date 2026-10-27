import { MessageSquareQuote, X } from 'lucide-react';
import { type RefObject, useEffect, useState } from 'react';

import { cn } from '@/lib/utils';

/*
 * Quoting part of a message (docs/DECISOES.md, "Citar na conversa"): select
 * text in a message, «Citar» puts it above the composer, and each quote may
 * carry a comment on that point. They go to the agent as quoted markdown
 * before the message, so it knows what is being answered.
 */

export interface Quote {
    id: number;
    author: string;
    text: string;
    comment: string;
}

/**
 * Over a selection inside a message: «Citar», which opens a small comment
 * box right there. Saving keeps the quote with its comment aside; the person
 * attaches them all to the message when ready (QuotesReady).
 */
export function QuoteSelection({ container, onQuote }: { container: RefObject<HTMLElement | null>; onQuote: (quote: Omit<Quote, 'id'>) => void }) {
    const [pending, setPending] = useState<{ author: string; text: string; top: number; bottom: number; left: number } | null>(null);
    const [editing, setEditing] = useState(false);
    const [comment, setComment] = useState('');

    useEffect(() => {
        const read = () => {
            if (editing) {
                return;
            }
            const selection = window.getSelection();
            const text = selection?.toString().trim() ?? '';
            if (!selection || selection.rangeCount === 0 || text === '') {
                setPending(null);
                return;
            }
            const range = selection.getRangeAt(0);
            const start = range.startContainer instanceof Element ? range.startContainer : range.startContainer.parentElement;
            const message = start?.closest<HTMLElement>('[data-quote-author]');
            if (!message || !container.current?.contains(message) || !message.contains(range.endContainer)) {
                setPending(null);
                return;
            }
            const rect = range.getBoundingClientRect();
            setPending({ author: message.dataset.quoteAuthor ?? '', text, top: rect.top, bottom: rect.bottom, left: rect.left + rect.width / 2 });
        };
        const later = () => window.setTimeout(read, 0);
        const clear = () => !editing && setPending(null);

        document.addEventListener('mouseup', later);
        document.addEventListener('keyup', later);
        document.addEventListener('scroll', clear, true);

        return () => {
            document.removeEventListener('mouseup', later);
            document.removeEventListener('keyup', later);
            document.removeEventListener('scroll', clear, true);
        };
    }, [container, editing]);

    const close = () => {
        setPending(null);
        setEditing(false);
        setComment('');
    };

    if (pending === null) {
        return null;
    }

    if (!editing) {
        return (
            <button
                type="button"
                // Keep the selection while clicking.
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => setEditing(true)}
                style={{ top: Math.max(8, pending.top - 40), left: pending.left }}
                className="fixed z-50 inline-flex h-8 -translate-x-1/2 items-center gap-1.5 rounded-lg bg-foreground px-3 text-xs font-medium text-background shadow-lg transition-opacity hover:opacity-90"
            >
                <MessageSquareQuote className="size-3.5" />
                Citar
            </button>
        );
    }

    const save = () => {
        onQuote({ author: pending.author, text: pending.text, comment });
        window.getSelection()?.removeAllRanges();
        close();
    };

    return (
        <div
            role="dialog"
            aria-label="Comentar a citação"
            // Above the selection, or below it when there is no room above.
            style={{
                top: pending.top > 200 ? pending.top - 8 : pending.bottom + 8,
                left: Math.min(Math.max(8, pending.left - 160), window.innerWidth - 328),
            }}
            className={cn(
                'fixed z-50 flex w-80 flex-col gap-2 rounded-xl bg-popover p-3 text-popover-foreground shadow-xl ring-1 ring-foreground/10',
                pending.top > 200 && '-translate-y-full',
            )}
            onKeyDown={(event) => event.key === 'Escape' && close()}
        >
            <p className="line-clamp-3 border-l-2 border-primary/50 pl-2 text-xs text-muted-foreground">{pending.text}</p>
            <textarea
                autoFocus
                rows={2}
                value={comment}
                onChange={(event) => setComment(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        save();
                    }
                }}
                placeholder="O seu comentário neste ponto"
                className="w-full resize-none bg-transparent text-sm outline-none placeholder:text-muted-foreground/70"
            />
            <div className="flex justify-end gap-2">
                <button
                    type="button"
                    onClick={close}
                    className="h-7 rounded-md px-2.5 text-xs text-muted-foreground hover:bg-accent hover:text-foreground"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    onClick={save}
                    className="h-7 rounded-md bg-primary px-3 text-xs font-medium text-primary-foreground hover:opacity-90"
                >
                    Guardar citação
                </button>
            </div>
        </div>
    );
}

/** The quotes kept so far, waiting to be attached to the message in one go. */
export function QuotesReady({ quotes, onAttach, onDiscard }: { quotes: Quote[]; onAttach: () => void; onDiscard: () => void }) {
    if (quotes.length === 0) {
        return null;
    }

    return (
        <div className="mb-2 flex items-center gap-2 px-1 text-xs text-muted-foreground">
            <MessageSquareQuote className="size-3.5 shrink-0" />
            <span className="min-w-0 truncate">
                {quotes.length === 1 ? '1 citação guardada' : `${quotes.length} citações guardadas`}
                {' · '}
                {quotes.filter((quote) => quote.comment.trim() !== '').length} com comentário
            </span>
            <span className="flex-1" />
            <button type="button" onClick={onDiscard} className="hover:text-foreground hover:underline">
                Descartar
            </button>
            <button type="button" onClick={onAttach} className="rounded-md bg-foreground px-2.5 py-1 font-medium text-background hover:opacity-90">
                Anexar à mensagem
            </button>
        </div>
    );
}

/** The quotes waiting above the composer, each with a comment on that point. */
export function QuoteTray({ quotes, onChange }: { quotes: Quote[]; onChange: (quotes: Quote[]) => void }) {
    if (quotes.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 px-3 pt-3">
            {quotes.map((quote) => (
                <div key={quote.id} className="flex items-start gap-2">
                    <div className="min-w-0 flex-1 border-l-2 border-primary/50 pl-3">
                        <p className="text-[11px] text-muted-foreground">{quote.author}</p>
                        <p className="line-clamp-3 text-xs whitespace-pre-line text-foreground/80">{quote.text}</p>
                        <input
                            value={quote.comment}
                            onChange={(event) => onChange(quotes.map((q) => (q.id === quote.id ? { ...q, comment: event.target.value } : q)))}
                            placeholder="Comentar este ponto (opcional)"
                            aria-label="Comentário à citação"
                            className="mt-1 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground/70"
                        />
                    </div>
                    <button
                        type="button"
                        onClick={() => onChange(quotes.filter((q) => q.id !== quote.id))}
                        aria-label="Tirar a citação"
                        className="inline-flex size-6 shrink-0 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
                    >
                        <X className="size-3.5" />
                    </button>
                </div>
            ))}
        </div>
    );
}

/** The message as the agent receives it: each quote as markdown, its comment under it, then the message. */
export function withQuotes(body: string, quotes: Quote[]): string {
    const quoted = quotes.map((quote) => {
        const lines = quote.text
            .split('\n')
            .map((line) => `> ${line}`)
            .join('\n');

        return `> **${quote.author}:**\n${lines}${quote.comment.trim() !== '' ? `\n\n${quote.comment.trim()}` : ''}`;
    });

    return [...quoted, body.trim()].filter((part) => part !== '').join('\n\n');
}
