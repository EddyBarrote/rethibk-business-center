import { ZoomIn, ZoomOut } from 'lucide-react';
import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react';

import { FormDialog } from '@/Components/Dialogs';

/** The square on screen where the photo is framed, in px. */
const VIEW = 288;
/** The side of the photo that is sent, in px. */
const OUTPUT = 512;
const MAX_ZOOM = 4;

interface Frame {
    zoom: number;
    x: number;
    y: number;
}

/**
 * Frame a photo before it is sent (docs/DECISOES.md, "Foto de perfil"): drag
 * to move it, zoom with the slider or the wheel. What is inside the circle
 * becomes a 512 px square JPEG.
 */
export function PhotoCropper({
    file,
    onClose,
    onCrop,
    processing = false,
}: {
    file: File | null;
    onClose: () => void;
    onCrop: (photo: File) => void;
    processing?: boolean;
}) {
    const [src, setSrc] = useState<string | null>(null);
    const [size, setSize] = useState<{ width: number; height: number } | null>(null);
    const [frame, setFrame] = useState<Frame>({ zoom: 1, x: 0, y: 0 });
    const image = useRef<HTMLImageElement>(null);
    const drag = useRef<{ pointer: number; x: number; y: number } | null>(null);

    useEffect(() => {
        if (!file) {
            return;
        }

        const url = URL.createObjectURL(file);
        setSrc(url);
        setSize(null);

        return () => URL.revokeObjectURL(url);
    }, [file]);

    const base = size ? VIEW / Math.min(size.width, size.height) : 1;

    /** Keep the circle covered: the photo never leaves an empty edge. */
    const clamp = (next: Frame): Frame => {
        if (!size) {
            return next;
        }

        const scale = base * next.zoom;

        return {
            zoom: next.zoom,
            x: Math.min(0, Math.max(VIEW - size.width * scale, next.x)),
            y: Math.min(0, Math.max(VIEW - size.height * scale, next.y)),
        };
    };

    /** Zoom around the middle of the square, so what is centred stays centred. */
    const zoomTo = (zoom: number) => {
        setFrame((current) => {
            const next = Math.min(MAX_ZOOM, Math.max(1, zoom));
            const ratio = next / current.zoom;

            return clamp({ zoom: next, x: VIEW / 2 - (VIEW / 2 - current.x) * ratio, y: VIEW / 2 - (VIEW / 2 - current.y) * ratio });
        });
    };

    const onLoad = () => {
        const element = image.current;
        if (!element) {
            return;
        }

        const width = element.naturalWidth;
        const height = element.naturalHeight;
        const scale = VIEW / Math.min(width, height);
        setSize({ width, height });
        setFrame({ zoom: 1, x: (VIEW - width * scale) / 2, y: (VIEW - height * scale) / 2 });
    };

    const onPointerDown = (event: ReactPointerEvent<HTMLDivElement>) => {
        event.currentTarget.setPointerCapture(event.pointerId);
        drag.current = { pointer: event.pointerId, x: event.clientX, y: event.clientY };
    };

    const onPointerMove = (event: ReactPointerEvent<HTMLDivElement>) => {
        const start = drag.current;
        if (!start || start.pointer !== event.pointerId) {
            return;
        }

        const dx = event.clientX - start.x;
        const dy = event.clientY - start.y;
        drag.current = { ...start, x: event.clientX, y: event.clientY };
        setFrame((current) => clamp({ ...current, x: current.x + dx, y: current.y + dy }));
    };

    const crop = () => {
        const element = image.current;
        if (!element || !size || !file) {
            return;
        }

        const scale = base * frame.zoom;
        const canvas = document.createElement('canvas');
        canvas.width = OUTPUT;
        canvas.height = OUTPUT;
        const context = canvas.getContext('2d');
        if (!context) {
            return;
        }

        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, OUTPUT, OUTPUT);
        context.imageSmoothingQuality = 'high';
        context.drawImage(element, -frame.x / scale, -frame.y / scale, VIEW / scale, VIEW / scale, 0, 0, OUTPUT, OUTPUT);
        canvas.toBlob(
            (blob) => blob && onCrop(new File([blob], `${file.name.replace(/\.[^.]+$/, '') || 'foto'}.jpg`, { type: 'image/jpeg' })),
            'image/jpeg',
            0.9,
        );
    };

    const scale = base * frame.zoom;

    return (
        <FormDialog
            open={file !== null}
            onOpenChange={(open) => !open && onClose()}
            title="Enquadrar a foto"
            description="Arraste para mover e use a barra para aproximar. Fica o que está dentro do círculo."
            submitLabel="Usar foto"
            processing={processing}
            disabled={!size}
            onSubmit={(event) => {
                event.preventDefault();
                crop();
            }}
            size="sm"
        >
            <div className="flex flex-col items-center gap-4">
                <div
                    className="relative cursor-grab touch-none overflow-hidden rounded-lg bg-muted select-none active:cursor-grabbing"
                    style={{ width: VIEW, height: VIEW }}
                    onPointerDown={onPointerDown}
                    onPointerMove={onPointerMove}
                    onPointerUp={() => (drag.current = null)}
                    onPointerCancel={() => (drag.current = null)}
                    onWheel={(event) => zoomTo(frame.zoom * (event.deltaY < 0 ? 1.08 : 1 / 1.08))}
                >
                    {src && (
                        <img
                            ref={image}
                            src={src}
                            alt=""
                            draggable={false}
                            onLoad={onLoad}
                            className="pointer-events-none absolute top-0 left-0 max-w-none origin-top-left"
                            style={
                                size
                                    ? { width: size.width * scale, height: size.height * scale, transform: `translate(${frame.x}px, ${frame.y}px)` }
                                    : { visibility: 'hidden' }
                            }
                        />
                    )}
                    <div className="pointer-events-none absolute inset-0 rounded-full shadow-[0_0_0_9999px_rgb(0_0_0/0.45)] ring-2 ring-white/80" />
                </div>
                <label className="flex w-full max-w-72 items-center gap-2 text-muted-foreground">
                    <ZoomOut className="size-4 shrink-0" aria-hidden="true" />
                    <span className="sr-only">Aproximar</span>
                    <input
                        type="range"
                        min={1}
                        max={MAX_ZOOM}
                        step={0.01}
                        value={frame.zoom}
                        disabled={!size}
                        onChange={(event) => zoomTo(Number(event.target.value))}
                        className="w-full accent-primary"
                    />
                    <ZoomIn className="size-4 shrink-0" aria-hidden="true" />
                </label>
            </div>
        </FormDialog>
    );
}
