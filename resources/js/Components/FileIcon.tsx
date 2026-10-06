import { FileImage, FileSpreadsheet, FileText, FileType2, type LucideIcon, NotebookText, Presentation } from 'lucide-react';

import { cn } from '@/lib/utils';

const icons: Record<string, LucideIcon> = {
    pdf: FileType2,
    docx: FileText,
    doc: FileText,
    xlsx: FileSpreadsheet,
    csv: FileSpreadsheet,
    pptx: Presentation,
    png: FileImage,
    jpg: FileImage,
    jpeg: FileImage,
};

/** Square file glyph for list rows: the extension decides the icon; articles get a notebook. */
export function FileIcon({ extension, className }: { extension?: string | null; className?: string }) {
    const Icon = extension ? (icons[extension] ?? FileText) : NotebookText;

    return (
        <span className={cn('inline-flex size-7 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground', className)}>
            <Icon className="size-3.5" />
        </span>
    );
}

/** Small mono extension tag ("PDF", "XLSX"). */
export function ExtensionTag({ extension }: { extension: string }) {
    return <span className="rounded bg-muted px-1 py-px font-mono text-[10px] text-muted-foreground uppercase">{extension}</span>;
}
