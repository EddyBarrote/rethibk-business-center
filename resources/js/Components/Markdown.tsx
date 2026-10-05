import { Link } from '@inertiajs/react';
import { type ReactNode, useLayoutEffect, useRef } from 'react';
import ReactMarkdown from 'react-markdown';
import remarkGfm from 'remark-gfm';

import { isConsolePath, pathLabel } from '@/lib/paths';

/**
 * Markdown written by agents (briefings, documents). Raw HTML is never
 * rendered; links open in the console when they are console paths.
 */
export function Markdown({ children }: { children: string }) {
    const root = useRef<HTMLDivElement>(null);

    // On a phone each table row becomes a card (the "table-stack" style): every cell is labelled with its column.
    useLayoutEffect(() => {
        root.current?.querySelectorAll('table').forEach((table) => {
            const heads = [...table.querySelectorAll('thead th')].map((th) => th.textContent?.trim() ?? '');
            table.classList.add('table-stack');
            table.querySelectorAll('tbody tr').forEach((row) =>
                [...row.children].forEach((cell, index) => {
                    if (index > 0 && heads[index]) {
                        cell.setAttribute('data-label', heads[index]);
                    }
                }),
            );
        });
    }, [children]);

    return (
        <div
            ref={root}
            className="prose-sm max-w-none min-w-0 text-sm leading-relaxed break-words [&_pre]:overflow-x-auto [&_pre]:rounded-lg [&_pre]:bg-muted [&_pre]:p-3 [&_a]:text-primary [&_a]:underline [&_blockquote]:border-l-2 [&_blockquote]:pl-3 [&_blockquote]:text-muted-foreground [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_h1]:mt-4 [&_h1]:text-lg [&_h1]:font-semibold [&_h2]:mt-4 [&_h2]:text-base [&_h2]:font-semibold [&_h3]:mt-3 [&_h3]:font-semibold [&_li]:my-0.5 [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-2 [&_table]:w-full [&_td]:align-top [&_hr]:my-4 [&_table]:text-left [&_td]:border-b [&_td]:px-2 [&_td]:py-1 [&_th]:border-b [&_th]:px-2 [&_th]:py-1 [&_th]:font-medium [&_ul]:list-disc [&_ul]:pl-5"
        >
            <ReactMarkdown
                remarkPlugins={[remarkGfm]}
                skipHtml
                components={{
                    // Wide tables scroll inside the text column instead of spilling over the page.
                    table: ({ node: _node, ...props }) => (
                        <div className="my-3 overflow-x-auto">
                            <table {...props} />
                        </div>
                    ),
                    a: ({ node: _node, href, children: label }) => <ConsoleLink href={href}>{label}</ConsoleLink>,
                    // Inline code (an address, a file name) may break only after . @ / _ -, never mid-word.
                    code: ({ node: _node, children: text, className, ...props }) =>
                        typeof text === 'string' && !className && !text.includes('\n') ? (
                            <code {...props} className="[overflow-wrap:normal] break-normal">
                                {text.split(/(?<=[./@_-])/).flatMap((part, index) => (index === 0 ? [part] : [<wbr key={index} />, part]))}
                            </code>
                        ) : (
                            <code {...props} className={className}>
                                {text}
                            </code>
                        ),
                }}
            >
                {children}
            </ReactMarkdown>
        </div>
    );
}

/**
 * A link an agent wrote. Console paths open in the console under the page's
 * name ("[/approvals](/approvals)" reads "Aprovações"); a path to a page the
 * console no longer has is plain text; anything else opens in a new tab.
 */
function ConsoleLink({ href, children }: { href?: string; children: ReactNode }) {
    if (!isConsolePath(href)) {
        return (
            <a href={href} target="_blank" rel="noreferrer noopener">
                {children}
            </a>
        );
    }
    const page = pathLabel(href);
    const label = page && typeof children === 'string' && children.trim() === href ? page.label : children;

    return page?.live ? <Link href={href}>{label}</Link> : <span>{label}</span>;
}
