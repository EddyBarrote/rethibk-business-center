import { Download, FileAudio, Mic } from 'lucide-react';
import { useState } from 'react';

import { FilePreview } from '@/Components/attachments/FilePreview';

import { ExtensionTag, FileIcon } from '@/Components/FileIcon';
import { fileSize, type MessageAttachment } from '@/lib/attachments';
import { cn } from '@/lib/utils';

/**
 * The files of a message in the thread: images as thumbnails, audio and video
 * with a player (and the transcription when there is one), everything else as
 * a file to open or download.
 */
export function MessageAttachments({ attachments, className }: { attachments: MessageAttachment[]; className?: string }) {
    const [open, setOpen] = useState<MessageAttachment | null>(null);

    if (attachments.length === 0) {
        return null;
    }

    const images = attachments.filter((a) => a.kind === 'image' && /^image\/(png|jpeg|webp|gif)$/.test(a.mime));
    const media = attachments.filter((a) => a.kind === 'audio' || a.kind === 'video');
    const files = attachments.filter((a) => !images.includes(a) && !media.includes(a));

    return (
        <div className={cn('flex flex-col gap-2', className)}>
            {images.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {images.map((image) => (
                        <a
                            key={image.id}
                            href={image.url}
                            target="_blank"
                            rel="noreferrer"
                            className="block overflow-hidden rounded-lg border bg-muted transition-opacity hover:opacity-90"
                            title={image.name}
                        >
                            <img
                                src={image.url}
                                alt={image.name}
                                loading="lazy"
                                className={cn('object-cover', images.length === 1 ? 'max-h-64 max-w-full sm:max-w-sm' : 'size-28')}
                            />
                        </a>
                    ))}
                </div>
            )}

            {media.map((item) => (
                <div key={item.id} className="flex w-full max-w-md flex-col gap-1.5 rounded-lg border bg-background p-2">
                    <div className="flex items-center gap-2 text-xs">
                        {item.source === 'recording' ? (
                            <Mic className="size-3.5 text-muted-foreground" />
                        ) : (
                            <FileAudio className="size-3.5 text-muted-foreground" />
                        )}
                        <span className="min-w-0 flex-1 truncate font-medium">{item.source === 'recording' ? 'Áudio gravado' : item.name}</span>
                        <span className="text-muted-foreground tabular-nums">{fileSize(item.size)}</span>
                        <a
                            href={`${item.url}?download=1`}
                            className="text-muted-foreground hover:text-foreground"
                            aria-label={`Descarregar ${item.name}`}
                            title="Descarregar"
                        >
                            <Download className="size-3.5" />
                        </a>
                    </div>
                    {item.kind === 'audio' ? (
                        <audio controls preload="metadata" src={item.url} className="h-9 w-full" />
                    ) : (
                        <video controls preload="metadata" src={item.url} className="max-h-72 w-full rounded-md bg-black" />
                    )}
                    {item.transcript && (
                        <details className="text-xs text-muted-foreground">
                            <summary className="cursor-pointer select-none hover:text-foreground">Transcrição</summary>
                            <p className="mt-1 whitespace-pre-wrap text-foreground/90">{item.transcript}</p>
                        </details>
                    )}
                </div>
            ))}

            {files.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {files.map((file) => (
                        <div key={file.id} className="flex max-w-72 items-center gap-2 rounded-lg border bg-background py-1.5 pr-1.5 pl-1.5 text-xs">
                            <FileIcon extension={file.extension} className="size-8" />
                            <button
                                type="button"
                                onClick={() => setOpen(file)}
                                className="flex min-w-0 flex-col text-left hover:underline"
                                title={`Abrir ${file.name}`}
                            >
                                <span className="truncate font-medium">{file.name}</span>
                                <span className="flex items-center gap-1.5 text-muted-foreground">
                                    {file.extension && <ExtensionTag extension={file.extension} />}
                                    {fileSize(file.size)}
                                    {file.source === 'generated' && ' · gerado pelo agente'}
                                    {file.source === 'knowledge' && ' · da base de conhecimento'}
                                </span>
                            </button>
                            <a
                                href={`${file.url}?download=1`}
                                className="ml-auto inline-flex size-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                                aria-label={`Descarregar ${file.name}`}
                                title="Descarregar"
                            >
                                <Download className="size-3.5" />
                            </a>
                        </div>
                    ))}
                </div>
            )}

            <FilePreview file={open} onClose={() => setOpen(null)} />
        </div>
    );
}
