import { Bold, Heading2, Italic, Link2, List, ListOrdered, Quote, Table } from 'lucide-react';
import { type ReactNode, useRef } from 'react';

import { Markdown } from '@/Components/Markdown';
import { Textarea } from '@/Components/ui/textarea';
import { cn } from '@/lib/utils';

type Edit = { label: string; icon: ReactNode; apply: (selected: string) => { text: string; select?: [number, number] } };

/*
 * The formatting buttons write the markdown for the person: a heading, bold,
 * a list. Each one wraps the selected text, or inserts an example to type over.
 */
const edits: Edit[] = [
    { label: 'Título', icon: <Heading2 />, apply: (t) => ({ text: `\n## ${t || 'Título'}\n`, select: [4, 4 + (t || 'Título').length] }) },
    { label: 'Negrito', icon: <Bold />, apply: (t) => ({ text: `**${t || 'texto'}**`, select: [2, 2 + (t || 'texto').length] }) },
    { label: 'Itálico', icon: <Italic />, apply: (t) => ({ text: `_${t || 'texto'}_`, select: [1, 1 + (t || 'texto').length] }) },
    {
        label: 'Lista',
        icon: <List />,
        apply: (t) => ({
            text: `\n${(t || 'Primeiro ponto\nSegundo ponto')
                .split('\n')
                .map((line) => `- ${line}`)
                .join('\n')}\n`,
        }),
    },
    {
        label: 'Lista numerada',
        icon: <ListOrdered />,
        apply: (t) => ({
            text: `\n${(t || 'Primeiro passo\nSegundo passo')
                .split('\n')
                .map((line, i) => `${i + 1}. ${line}`)
                .join('\n')}\n`,
        }),
    },
    { label: 'Citação', icon: <Quote />, apply: (t) => ({ text: `\n> ${t || 'Nota importante'}\n` }) },
    {
        label: 'Ligação',
        icon: <Link2 />,
        apply: (t) => ({ text: `[${t || 'texto'}](https://)`, select: [(t || 'texto').length + 3, (t || 'texto').length + 11] }),
    },
    { label: 'Tabela', icon: <Table />, apply: () => ({ text: '\n| Coluna | Coluna |\n|---|---|\n| | |\n' }) },
];

/** A markdown field that reads like an editor: formatting buttons, and the result beside it on wide screens. */
export function MarkdownEditor({
    id,
    value,
    onChange,
    placeholder,
    invalid = false,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    invalid?: boolean;
}) {
    const area = useRef<HTMLTextAreaElement>(null);

    const run = (edit: Edit) => {
        const field = area.current;
        if (!field) {
            return;
        }
        const [start, end] = [field.selectionStart, field.selectionEnd];
        const { text, select } = edit.apply(value.slice(start, end));
        onChange(value.slice(0, start) + text + value.slice(end));
        requestAnimationFrame(() => {
            field.focus();
            field.setSelectionRange(start + (select?.[0] ?? text.length), start + (select?.[1] ?? text.length));
        });
    };

    return (
        <div className="grid overflow-hidden rounded-xl border bg-card xl:grid-cols-2">
            <div className="flex min-w-0 flex-col xl:border-r">
                <div className="flex flex-wrap items-center gap-0.5 border-b px-2 py-1.5" role="toolbar" aria-label="Formatação">
                    {edits.map((edit) => (
                        <button
                            key={edit.label}
                            type="button"
                            onClick={() => run(edit)}
                            title={edit.label}
                            aria-label={edit.label}
                            className="inline-flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground [&_svg]:size-4"
                        >
                            {edit.icon}
                        </button>
                    ))}
                </div>
                <Textarea
                    ref={area}
                    id={id}
                    rows={22}
                    className={cn('min-h-[28rem] flex-1 resize-y rounded-none border-0 text-sm leading-relaxed shadow-none focus-visible:ring-0')}
                    placeholder={placeholder}
                    value={value}
                    onChange={(e) => onChange(e.target.value)}
                    aria-invalid={invalid}
                />
            </div>
            <div className="min-w-0 border-t bg-background/40 xl:border-t-0">
                <p className="border-b px-4 py-2.5 text-xs font-medium text-muted-foreground">Como fica</p>
                <div className="max-h-[36rem] overflow-y-auto px-5 py-4">
                    {value.trim() ? (
                        <Markdown>{value}</Markdown>
                    ) : (
                        <p className="text-sm text-muted-foreground">O artigo aparece aqui à medida que escreve.</p>
                    )}
                </div>
            </div>
        </div>
    );
}
