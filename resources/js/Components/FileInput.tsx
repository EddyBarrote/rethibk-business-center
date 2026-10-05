import { FileUp, X } from 'lucide-react';
import { type DragEvent, useRef, useState } from 'react';

import { Button } from '@/Components/ui/button';
import { bytes } from '@/lib/format';
import { cn } from '@/lib/utils';

/**
 * A file picker that speaks Portuguese and takes a dropped file: the browser's own
 * input reads "Choose File / No file chosen" whatever the page's language.
 */
export function FileInput({
    id,
    accept,
    file,
    onChange,
    hint,
    invalid = false,
    className,
}: {
    id: string;
    accept?: string;
    file: File | null;
    onChange: (file: File | null) => void;
    /** What can be sent, e.g. "PNG ou JPG até 2 MB". */
    hint?: string;
    invalid?: boolean;
    className?: string;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [dragging, setDragging] = useState(false);

    const drop = (event: DragEvent) => {
        event.preventDefault();
        setDragging(false);
        const dropped = event.dataTransfer.files?.[0];
        if (dropped) {
            onChange(dropped);
        }
    };

    return (
        <div
            onDragOver={(event) => {
                event.preventDefault();
                setDragging(true);
            }}
            onDragLeave={() => setDragging(false)}
            onDrop={drop}
            className={cn(
                'flex items-center gap-3 rounded-md border border-dashed border-input px-3 py-2.5 transition-colors',
                dragging && 'border-primary bg-primary/5',
                invalid && 'border-destructive',
                className,
            )}
        >
            <input
                ref={input}
                id={id}
                type="file"
                accept={accept}
                className="sr-only"
                onChange={(event) => onChange(event.target.files?.[0] ?? null)}
                aria-invalid={invalid}
            />
            <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                <FileUp className="size-4" />
            </span>
            <div className="min-w-0 flex-1 text-sm">
                {file ? (
                    <>
                        <p className="truncate font-medium">{file.name}</p>
                        <p className="font-mono text-xs text-muted-foreground">{bytes(file.size)}</p>
                    </>
                ) : (
                    <>
                        <p className="text-muted-foreground">Arraste um ficheiro para aqui ou escolha do computador.</p>
                        {hint && <p className="text-xs text-muted-foreground/80">{hint}</p>}
                    </>
                )}
            </div>
            {file ? (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Retirar ficheiro"
                    onClick={() => {
                        onChange(null);
                        if (input.current) {
                            input.current.value = '';
                        }
                    }}
                >
                    <X />
                </Button>
            ) : (
                <Button type="button" variant="outline" size="sm" onClick={() => input.current?.click()}>
                    Escolher ficheiro
                </Button>
            )}
        </div>
    );
}
