/** A file chosen or dropped, with its path inside a dragged folder ("Contratos/2026/x.pdf"). */
export interface PickedFile {
    file: File;
    path: string | null;
}

interface Entry {
    isFile: boolean;
    isDirectory: boolean;
    name: string;
    fullPath: string;
    file?: (success: (file: File) => void, failure: (error: unknown) => void) => void;
    createReader?: () => { readEntries: (success: (entries: Entry[]) => void, failure: (error: unknown) => void) => void };
}

/** Files of an input, keeping the folder path when a folder was picked (webkitdirectory). */
export function fromInput(list: FileList | null): PickedFile[] {
    return Array.from(list ?? []).map((file) => ({ file, path: file.webkitRelativePath || null }));
}

/**
 * Files dropped on the page. Dragged folders are walked (every file inside,
 * with its path); plain files keep no path.
 */
export async function fromDrop(data: DataTransfer): Promise<PickedFile[]> {
    const entries = Array.from(data.items)
        .map((item) => (item.webkitGetAsEntry?.() as Entry | null) ?? null)
        .filter((entry): entry is Entry => entry !== null);

    if (entries.length === 0 || !entries.some((entry) => entry.isDirectory)) {
        return Array.from(data.files).map((file) => ({ file, path: null }));
    }

    const out: PickedFile[] = [];
    await Promise.all(entries.map((entry) => walk(entry, out)));

    return out;
}

async function walk(entry: Entry, out: PickedFile[]): Promise<void> {
    if (entry.isFile && entry.file) {
        const file = await new Promise<File>((resolve, reject) => entry.file?.(resolve, reject)).catch(() => null);

        // Hidden system files (.DS_Store, Thumbs.db) stay out.
        if (file && !file.name.startsWith('.') && file.name !== 'Thumbs.db') {
            out.push({ file, path: entry.fullPath.replace(/^\//, '') });
        }

        return;
    }

    if (entry.isDirectory && entry.createReader) {
        const reader = entry.createReader();

        // readEntries returns at most 100 entries per call: read until empty.
        for (;;) {
            const batch = await new Promise<Entry[]>((resolve, reject) => reader.readEntries(resolve, reject)).catch(() => []);

            if (batch.length === 0) {
                break;
            }

            await Promise.all(batch.map((child) => walk(child, out)));
        }
    }
}
