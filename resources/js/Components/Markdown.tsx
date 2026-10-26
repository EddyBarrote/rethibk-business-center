import 'katex/dist/katex.min.css';

import { Link } from '@inertiajs/react';
import { type ReactNode, useLayoutEffect, useRef } from 'react';
import ReactMarkdown from 'react-markdown';
import rehypeKatex from 'rehype-katex';
import remarkGfm from 'remark-gfm';
import remarkMath from 'remark-math';

import { prepareMath } from '@/lib/math';
import { isConsolePath, pathLabel } from '@/lib/paths';
import { cn } from '@/lib/utils';

/** A cell that holds a number, an amount or a percentage ("1.234,56 MZN", "-12%", "MZN 80"). */
const NUMBER = /^[(\-−+]?\s*(?:[A-Z]{3}\s*|[$€£]\s*)?\d[\d\s.,  ]*(?:\s*(?:%|[A-Z]{3}|MT|un\.?|x))?\)?$/;
const TOTAL = /^(total|subtotal|soma|saldo|imposto a pagar|a pagar|diferença)\b/i;

/**
 * Markdown written by agents (briefings, documents, chat replies): GFM tables,
 * lists, code, quotes and formulas ($…$, $$…$$) drawn with KaTeX. Raw HTML is
 * never rendered; links open in the console when they are console paths.
 */
export function Markdown({ children, className }: { children: string; className?: string }) {
    const root = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        root.current?.querySelectorAll('table').forEach((table) => {
            const heads = [...table.querySelectorAll('thead th')];
            const rows = [...table.querySelectorAll('tbody tr')];

            // Columns of numbers line up on the right, unless the agent aligned them itself.
            heads.forEach((head, index) => {
                const cells = rows.map((row) => row.children[index]).filter((cell) => cell && cell.textContent?.trim() !== '');
                if (
                    !(head as HTMLElement).style.textAlign &&
                    cells.length > 0 &&
                    cells.every((cell) => NUMBER.test(cell.textContent?.trim() ?? ''))
                ) {
                    [head, ...cells].forEach((cell) => cell.classList.add('md-num'));
                }
            });

            // A last row named "Total" (or Subtotal, Saldo…) stands out.
            const last = rows.at(-1);
            if (rows.length > 1 && last && TOTAL.test(last.children[0]?.textContent?.replace(/[*_]/g, '').trim() ?? '')) {
                last.classList.add('md-total');
            }

            // On a phone each table row becomes a card (the "table-stack" style): every cell is labelled with its column.
            table.classList.add('table-stack');
            rows.forEach((row) =>
                [...row.children].forEach((cell, index) => {
                    const head = heads[index]?.textContent?.trim();
                    if (index > 0 && head) {
                        cell.setAttribute('data-label', head);
                    }
                }),
            );
        });
    }, [children]);

    return (
        <div ref={root} className={cn('markdown', className)}>
            <ReactMarkdown
                remarkPlugins={[remarkGfm, [remarkMath, { singleDollarTextMath: true }]]}
                rehypePlugins={[[rehypeKatex, { throwOnError: false, strict: 'ignore', output: 'htmlAndMathml' }]]}
                skipHtml
                components={{
                    // Wide tables scroll inside the text column instead of spilling over the page.
                    table: ({ node: _node, ...props }) => (
                        <div className="md-table">
                            <table {...props} />
                        </div>
                    ),
                    a: ({ node: _node, href, children: label }) => <ConsoleLink href={href}>{label}</ConsoleLink>,
                    // Inline code (an address, a file name) may break only after . @ / _ -, never mid-word.
                    code: ({ node: _node, children: text, className: language, ...props }) =>
                        typeof text === 'string' && !language && !text.includes('\n') ? (
                            <code {...props} className="[overflow-wrap:normal] break-normal">
                                {text.split(/(?<=[./@_-])/).flatMap((part, index) => (index === 0 ? [part] : [<wbr key={index} />, part]))}
                            </code>
                        ) : (
                            <code {...props} className={language}>
                                {text}
                            </code>
                        ),
                }}
            >
                {prepareMath(children)}
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
