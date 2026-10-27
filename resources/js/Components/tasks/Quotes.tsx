import { MessageSquareQuote, X } from 'lucide-react';
import { type RefObject, useEffect, useState } from 'react';

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

/** The floating «Citar» button over a selection inside a message of the thread. */
export function QuoteSelection({
    container,
    onQuote,
}: {
    container: RefObject<HTMLElement | null>;
    onQuote: (quote: Omit<Quote, 'id' | 'comment'>) => void;
}) {
    const [pending, setPending] = useState<{ author: string; text: string; top: number; left: number } | null>(null);

    useEffect(() => {
        const read = () => {
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
            setPending({ author: message.dataset.quoteAuthor ?? '', text, top: rect.top - 40, left: rect.left + rect.width / 2 });
        };
        const later = () => window.setTimeout(read, 0);
        const clear = () => setPending(null);

        document.addEventListener('mouseup', later);
        document.addEventListener('keyup', later);
        document.addEventListener('scroll', clear, true);

        return () => {
            document.removeEventListener('mouseup', later);
            document.removeEventListener('keyup', later);
            document.removeEventListener('scroll', clear, true);
        };
    }, [container]);

    if (pending === null) {
        return null;
    }

    return (
        <button
            type="button"
            // Keep the selection while clicking.
            onMouseDown={(event) => event.preventDefault()}
            onClick={() => {
                onQuote({ author: pending.author, text: pending.text });
                window.getSelection()?.removeAllRanges();
                setPending(null);
            }}
            style={{ top: Math.max(8, pending.top), left: pending.left }}
            className="fixed z-50 inline-flex h-8 -translate-x-1/2 items-center gap-1.5 rounded-lg bg-foreground px-3 text-xs font-medium text-background shadow-lg transition-opacity hover:opacity-90"
        >
            <MessageSquareQuote className="size-3.5" />
            Citar
        </button>
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
