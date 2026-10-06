import { AlertCircle, Check, FileAudio, Loader2, Mic, Paperclip, Upload, X } from 'lucide-react';
import { type ClipboardEvent, type DragEvent, useRef, useState } from 'react';

import { FileIcon } from '@/Components/FileIcon';
import { Button } from '@/Components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/Components/ui/tooltip';
import type { PendingAttachment } from '@/hooks/useAttachments';
import { useWavRecorder } from '@/hooks/useWavRecorder';
import { clock, fileSize } from '@/lib/attachments';
import { cn } from '@/lib/utils';

/*
 * The attachment parts of a composer (docs/DECISOES.md, "Anexos nas
 * conversas"): the paperclip, the microphone, the tray of files being
 * uploaded, and dropping or pasting files anywhere on the composer.
 */

/** Files dropped on the area, and files pasted into the text. */
export function useFileDrop(onFiles: (files: File[]) => void, enabled = true) {
    const [dragging, setDragging] = useState(false);
    const depth = useRef(0);
    const hasFiles = (event: DragEvent) => Array.from(event.dataTransfer?.types ?? []).includes('Files');

    return {
        dragging,
        dropProps: enabled
            ? {
                  onDragEnter: (event: DragEvent) => {
                      if (!hasFiles(event)) return;
                      event.preventDefault();
                      depth.current += 1;
                      setDragging(true);
                  },
                  onDragOver: (event: DragEvent) => {
                      if (!hasFiles(event)) return;
                      event.preventDefault();
                      event.dataTransfer.dropEffect = 'copy';
                  },
                  onDragLeave: (event: DragEvent) => {
                      if (!hasFiles(event)) return;
                      depth.current = Math.max(0, depth.current - 1);
                      if (depth.current === 0) setDragging(false);
                  },
                  onDrop: (event: DragEvent) => {
                      if (!hasFiles(event)) return;
                      event.preventDefault();
                      depth.current = 0;
                      setDragging(false);
                      onFiles(Array.from(event.dataTransfer.files));
                  },
              }
            : {},
        onPaste: (event: ClipboardEvent) => {
            const files = Array.from(event.clipboardData?.files ?? []);
            if (enabled && files.length > 0) {
                event.preventDefault();
                // Pasted screenshots arrive as "image.png": give them a readable name.
                onFiles(
                    files.map((file, i) =>
                        file.name === 'image.png' || file.name === ''
                            ? new File([file], `captura-${new Date().toLocaleTimeString('pt-PT').replaceAll(':', 'h')}${i ? `-${i + 1}` : ''}.png`, {
                                  type: file.type,
                              })
                            : file,
                    ),
                );
            }
        },
    };
}

export function DropOverlay({ show }: { show: boolean }) {
    if (!show) return null;

    return (
        <div className="pointer-events-none absolute inset-0 z-20 flex flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-primary bg-background/90 text-sm">
            <Upload className="size-5 text-primary" />
            <span className="font-medium">Largue para anexar</span>
        </div>
    );
}

export function AttachButton({ extensions, onFiles, disabled }: { extensions: string[]; onFiles: (files: File[]) => void; disabled?: boolean }) {
    const input = useRef<HTMLInputElement>(null);

    return (
        <>
            <input
                ref={input}
                type="file"
                multiple
                hidden
                accept={extensions.map((extension) => `.${extension}`).join(',')}
                onChange={(event) => {
                    onFiles(Array.from(event.target.files ?? []));
                    event.target.value = '';
                }}
            />
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        disabled={disabled}
                        onClick={() => input.current?.click()}
                        aria-label="Anexar ficheiros"
                    >
                        <Paperclip />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Anexar ficheiros (ou arraste, ou cole)</TooltipContent>
            </Tooltip>
        </>
    );
}

/**
 * The microphone: records until stopped (or the company's limit), then the
 * recording joins the tray like any other file.
 */
export function RecordControl({ maxSeconds, onRecorded, disabled }: { maxSeconds: number; onRecorded: (file: File) => void; disabled?: boolean }) {
    const recorder = useWavRecorder(maxSeconds, onRecorded);

    if (recorder.recording) {
        return (
            <div className="flex items-center gap-2 rounded-lg bg-destructive/10 py-1 pr-1 pl-2.5 text-sm" role="status" aria-live="polite">
                <span className="relative flex size-2.5">
                    <span className="absolute inline-flex size-full animate-ping rounded-full bg-destructive opacity-60" />
                    <span className="relative inline-flex size-2.5 rounded-full bg-destructive" />
                </span>
                <span className="font-mono text-xs tabular-nums">
                    {clock(recorder.seconds)} <span className="text-muted-foreground">/ {clock(maxSeconds)}</span>
                </span>
                <span className="hidden h-1.5 w-16 overflow-hidden rounded-full bg-muted sm:block" aria-hidden>
                    <span
                        className="block h-full rounded-full bg-destructive transition-[width]"
                        style={{ width: `${Math.min(100, recorder.level * 140)}%` }}
                    />
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    onClick={recorder.cancel}
                    aria-label="Cancelar gravação"
                    title="Cancelar"
                >
                    <X />
                </Button>
                <Button type="button" size="icon" className="size-7" onClick={recorder.stop} aria-label="Terminar gravação" title="Terminar e anexar">
                    <Check />
                </Button>
            </div>
        );
    }

    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        disabled={disabled}
                        onClick={() => void recorder.start()}
                        aria-label="Gravar áudio"
                    >
                        <Mic />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>Gravar áudio (até {clock(maxSeconds)})</TooltipContent>
            </Tooltip>
            {recorder.error && (
                <span className="flex items-center gap-1 text-xs text-destructive">
                    <AlertCircle className="size-3.5" />
                    {recorder.error}
                </span>
            )}
        </>
    );
}

/** The files of the message being written, uploading or ready. */
export function AttachmentTray({
    items,
    onRemove,
    notice,
    onDismissNotice,
}: {
    items: PendingAttachment[];
    onRemove: (key: string) => void;
    notice?: string | null;
    onDismissNotice?: () => void;
}) {
    if (items.length === 0 && !notice) {
        return null;
    }

    return (
        <div className="flex flex-col gap-2 px-3 pt-3">
            {items.length > 0 && (
                <ul className="flex flex-wrap gap-2" aria-label="Anexos">
                    {items.map((item) => (
                        <li
                            key={item.key}
                            className={cn(
                                'group relative flex h-14 max-w-60 items-center gap-2 overflow-hidden rounded-lg border bg-background pr-8 pl-1.5 text-xs',
                                item.error && 'border-destructive/50 bg-destructive/5',
                            )}
                            title={item.error ?? item.name}
                        >
                            {item.preview ? (
                                <img src={item.preview} alt="" className="size-11 shrink-0 rounded-md object-cover" />
                            ) : item.kind === 'audio' ? (
                                <span className="inline-flex size-11 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                                    <FileAudio className="size-4" />
                                </span>
                            ) : (
                                <FileIcon extension={item.name.split('.').pop()?.toLowerCase()} className="size-11" />
                            )}
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="truncate font-medium">{item.source === 'recording' ? 'Áudio gravado' : item.name}</span>
                                <span className={cn('truncate text-muted-foreground', item.error && 'text-destructive')}>
                                    {item.error
                                        ? item.error
                                        : item.id === null
                                          ? `A carregar… ${Math.round(item.progress * 100)}%`
                                          : fileSize(item.size)}
                                </span>
                            </span>
                            {item.id === null && !item.error && (
                                <span className="absolute inset-x-0 bottom-0 h-0.5 bg-muted">
                                    <span className="block h-full bg-primary transition-[width]" style={{ width: `${item.progress * 100}%` }} />
                                </span>
                            )}
                            <button
                                type="button"
                                onClick={() => onRemove(item.key)}
                                className="absolute top-1 right-1 inline-flex size-6 items-center justify-center rounded-md text-muted-foreground hover:bg-accent hover:text-foreground"
                                aria-label={`Retirar ${item.name}`}
                            >
                                {item.id === null && !item.error ? <Loader2 className="size-3.5 animate-spin group-hover:hidden" /> : null}
                                <X className={cn('size-3.5', item.id === null && !item.error && 'hidden group-hover:block')} />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {notice && (
                <p className="flex items-start gap-1.5 text-xs text-destructive">
                    <AlertCircle className="mt-px size-3.5 shrink-0" />
                    <span className="flex-1">{notice}</span>
                    {onDismissNotice && (
                        <button
                            type="button"
                            onClick={onDismissNotice}
                            className="text-muted-foreground hover:text-foreground"
                            aria-label="Fechar aviso"
                        >
                            <X className="size-3.5" />
                        </button>
                    )}
                </p>
            )}
        </div>
    );
}
