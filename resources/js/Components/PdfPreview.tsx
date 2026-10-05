import { Download, ExternalLink, FileText } from 'lucide-react';

import { Button } from '@/Components/ui/button';

/**
 * A PDF shown in the page. Browsers without a PDF viewer (most phones,
 * headless browsers) render the fallback inside <object> instead of an empty
 * grey frame: the file's name with Abrir and Descarregar.
 */
export function PdfPreview({ src, title }: { src: string; title: string }) {
    const inline = `${src}?inline=1`;

    return (
        <object data={inline} type="application/pdf" aria-label={title} className="h-[78vh] w-full rounded-xl border bg-card">
            <div className="flex h-full min-h-72 flex-col items-center justify-center gap-4 rounded-xl border border-dashed bg-card px-6 py-10 text-center">
                <span className="flex size-12 items-center justify-center rounded-xl bg-muted">
                    <FileText className="size-6 text-muted-foreground" />
                </span>
                <div className="space-y-1">
                    <p className="text-sm font-medium">{title}</p>
                    <p className="text-sm text-muted-foreground">Este browser não mostra PDF dentro da página. Abra-o ou descarregue-o.</p>
                </div>
                <div className="flex flex-wrap justify-center gap-2">
                    <Button variant="outline" asChild>
                        <a href={inline} target="_blank" rel="noreferrer">
                            <ExternalLink />
                            Abrir
                        </a>
                    </Button>
                    <Button asChild>
                        <a href={src}>
                            <Download />
                            Descarregar
                        </a>
                    </Button>
                </div>
            </div>
        </object>
    );
}
