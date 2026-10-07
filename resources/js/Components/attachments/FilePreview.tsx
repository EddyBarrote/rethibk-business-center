import { Download, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

import { ExtensionTag } from '@/Components/FileIcon';
import { Markdown } from '@/Components/Markdown';
import { PdfPreview } from '@/Components/PdfPreview';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { fileSize, type MessageAttachment } from '@/lib/attachments';

/**
 * A file of the conversation opened in place: a PDF in the page; Word, Excel,
 * PowerPoint and text as their content (the Markdown an agent wrote for a
 * document it generated, the extracted text otherwise), with the download.
 */
export function FilePreview({ file, onClose }: { file: MessageAttachment | null; onClose: () => void }) {
    return (
        <Dialog open={file !== null} onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-4 sm:max-w-4xl">
                {file && (
                    <>
                        <DialogHeader className="pr-8">
                            <DialogTitle className="flex min-w-0 items-center gap-2">
                                <span className="truncate">{file.name}</span>
                                {file.extension && <ExtensionTag extension={file.extension} />}
                            </DialogTitle>
                            <DialogDescription>
                                {fileSize(file.size)}
                                {file.source === 'generated' && ' · gerado pelo agente'}
                                {file.source === 'knowledge' && ' · da base de conhecimento'}
                                {file.source === 'erp' && ' · do ERP'}
                                {file.kind !== 'pdf' && ' · pré-visualização do conteúdo; o ficheiro tem a marca e o layout originais'}
                            </DialogDescription>
                        </DialogHeader>
                        <div className="min-h-0 flex-1 overflow-auto">
                            {file.kind === 'pdf' ? <PdfPreview src={file.url} title={file.name} /> : <Content file={file} />}
                        </div>
                        <div className="flex justify-end">
                            <Button asChild>
                                <a href={`${file.url}?download=1`}>
                                    <Download />
                                    Descarregar
                                </a>
                            </Button>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Content({ file }: { file: MessageAttachment }) {
    const [state, setState] = useState<{ format: 'markdown' | 'sheet' | 'text'; text: string | null } | 'loading' | 'error'>('loading');

    useEffect(() => {
        let alive = true;
        setState('loading');
        fetch(`${file.url}/preview`, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((response) => (response.ok ? response.json() : Promise.reject(new Error())))
            .then((data) => alive && setState(data))
            .catch(() => alive && setState('error'));

        return () => {
            alive = false;
        };
    }, [file.url]);

    if (state === 'loading') {
        return (
            <div className="flex items-center justify-center gap-2 py-16 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />A abrir…
            </div>
        );
    }

    if (state === 'error' || state.text === null) {
        return (
            <p className="py-16 text-center text-sm text-muted-foreground">Não há pré-visualização para este ficheiro. Descarregue-o para o abrir.</p>
        );
    }

    if (state.format === 'markdown') {
        return (
            <article className="rounded-xl border bg-card px-6 py-5">
                <Markdown>{state.text}</Markdown>
            </article>
        );
    }

    if (state.format === 'sheet') {
        const sheets = state.text.split(/\n\s*\n/).map((block) => block.split('\n').map((row) => row.split('\t')));

        return (
            <div className="flex flex-col gap-4">
                {sheets
                    .filter((rows) => rows.some((row) => row.some((cell) => cell.trim() !== '')))
                    .map((rows, index) => (
                        <div key={index} className="overflow-auto rounded-xl border">
                            <table className="w-full text-sm">
                                <tbody>
                                    {rows.map((row, r) => (
                                        <tr key={r} className={r === 0 ? 'bg-muted/60 font-medium' : 'border-t'}>
                                            {row.map((cell, c) => (
                                                <td key={c} className="px-3 py-1.5 whitespace-nowrap tabular-nums">
                                                    {cell}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    ))}
            </div>
        );
    }

    return <pre className="rounded-xl border bg-card px-5 py-4 font-sans text-sm whitespace-pre-wrap">{state.text}</pre>;
}
