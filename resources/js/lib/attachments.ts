/*
 * Files in conversations (docs/DECISOES.md, "Anexos nas conversas"): each file
 * is uploaded in parts as soon as it is added, so sending the message is
 * instant and any file size works under PHP's own limits.
 */

export type AttachmentKind = 'image' | 'audio' | 'video' | 'pdf' | 'document' | 'text';

/** A file already in the thread. */
export interface MessageAttachment {
    id: number;
    name: string;
    kind: AttachmentKind;
    mime: string;
    size: number;
    extension: string;
    source: 'upload' | 'recording' | 'generated' | 'knowledge' | 'erp';
    url: string;
    transcript: string | null;
}

/** What the company accepts (Definições › Anexos). */
export interface AttachmentLimits {
    max_mb: number;
    max_files: number;
    audio_max_seconds: number;
    types: string[];
    extensions: string[];
    chunk_bytes: number;
}

export function xsrf(): Record<string, string> {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
    };
}

async function failure(response: Response): Promise<Error> {
    const data = (await response.json().catch(() => ({}))) as { message?: string; errors?: Record<string, string[]> };
    const first = data.errors ? Object.values(data.errors)[0]?.[0] : undefined;

    return new Error(first ?? data.message ?? 'O envio falhou.');
}

/**
 * Upload one file in parts; reports progress from 0 to 1 and returns its id.
 */
export async function uploadAttachment(
    file: File,
    target: { task_id?: number; agent_id?: number; mailbox_id?: number },
    chunkBytes: number,
    onProgress: (progress: number) => void,
    signal: AbortSignal,
    source: 'upload' | 'recording' | 'repository' = 'upload',
): Promise<{ id: number; kind: AttachmentKind }> {
    const start = await fetch('/files', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { ...xsrf(), 'Content-Type': 'application/json' },
        body: JSON.stringify({ filename: file.name, size: file.size, mime: file.type || null, source, ...target }),
        signal,
    });

    if (!start.ok) {
        throw await failure(start);
    }

    const { id } = (await start.json()) as { id: number };
    let offset = 0;
    let kind: AttachmentKind = kindOf(file.type, file.name);

    while (offset < file.size) {
        const body = new FormData();
        body.append('offset', String(offset));
        body.append('chunk', file.slice(offset, offset + chunkBytes), file.name);

        const response = await fetch(`/files/${id}/chunk`, { method: 'POST', credentials: 'same-origin', headers: xsrf(), body, signal });

        if (!response.ok) {
            throw await failure(response);
        }

        const data = (await response.json()) as { received: number; kind: AttachmentKind };
        offset = data.received;
        kind = data.kind;
        onProgress(offset / file.size);
    }

    return { id, kind };
}

export function discardAttachment(id: number): void {
    void fetch(`/files/${id}`, { method: 'DELETE', credentials: 'same-origin', headers: xsrf() });
}

export function kindOf(mime: string, name: string): AttachmentKind {
    const extension = name.split('.').pop()?.toLowerCase() ?? '';

    if (mime.startsWith('image/')) return 'image';
    if (mime.startsWith('audio/') || ['wav', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'weba'].includes(extension)) return 'audio';
    if (mime.startsWith('video/')) return 'video';
    if (mime === 'application/pdf' || extension === 'pdf') return 'pdf';
    if (mime.startsWith('text/') || ['txt', 'md', 'csv', 'json', 'xml'].includes(extension)) return 'text';

    return 'document';
}

export function fileSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

export function clock(seconds: number): string {
    const s = Math.max(0, Math.floor(seconds));

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}
