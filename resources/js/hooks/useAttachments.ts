import { useCallback, useEffect, useRef, useState } from 'react';

import { type AttachmentKind, type AttachmentLimits, discardAttachment, kindOf, uploadAttachment } from '@/lib/attachments';

/** A file in the composer, uploading or ready to send. */
export interface PendingAttachment {
    key: string;
    name: string;
    size: number;
    kind: AttachmentKind;
    preview: string | null;
    progress: number;
    id: number | null;
    error: string | null;
    source: 'upload' | 'recording';
    controller: AbortController;
}

/**
 * The files of the message being written: checked against the company's
 * limits, uploaded at once in parts, removed (and deleted on the server) on
 * request. The message sends their ids.
 */
export function useAttachments(limits: AttachmentLimits, target: { task_id?: number; agent_id?: number; mailbox_id?: number }) {
    const [items, setItems] = useState<PendingAttachment[]>([]);
    const [notice, setNotice] = useState<string | null>(null);
    const itemsRef = useRef(items);
    itemsRef.current = items;

    const patch = (key: string, change: Partial<PendingAttachment>) =>
        setItems((current) => current.map((item) => (item.key === key ? { ...item, ...change } : item)));

    const add = useCallback(
        (files: File[], source: 'upload' | 'recording' = 'upload') => {
            const problems: string[] = [];
            const room = limits.max_files - itemsRef.current.length;
            const accepted: [PendingAttachment, File][] = [];

            files.forEach((file, index) => {
                const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

                if (index >= room) {
                    problems.push(`No máximo ${limits.max_files} ficheiros por mensagem.`);
                } else if (!limits.extensions.includes(extension)) {
                    problems.push(`«${file.name}»: este tipo não é aceite.`);
                } else if (file.size > limits.max_mb * 1024 * 1024) {
                    problems.push(`«${file.name}» passa de ${limits.max_mb} MB.`);
                } else if (file.size === 0) {
                    problems.push(`«${file.name}» está vazio.`);
                } else {
                    const kind = kindOf(file.type, file.name);
                    accepted.push([
                        {
                            key: `${Date.now()}-${Math.random().toString(36).slice(2)}`,
                            name: file.name,
                            size: file.size,
                            kind,
                            preview: kind === 'image' ? URL.createObjectURL(file) : null,
                            progress: 0,
                            id: null,
                            error: null,
                            source,
                            controller: new AbortController(),
                        },
                        file,
                    ]);
                }
            });

            setNotice(problems.length ? [...new Set(problems)].join(' ') : null);

            if (accepted.length === 0) {
                return;
            }

            setItems((current) => [...current, ...accepted.map(([item]) => item)]);

            accepted.forEach(([item, file]) => {
                uploadAttachment(file, target, limits.chunk_bytes, (progress) => patch(item.key, { progress }), item.controller.signal, source)
                    .then(({ id, kind }) => patch(item.key, { id, kind, progress: 1 }))
                    .catch((error: Error) => {
                        if (error.name !== 'AbortError') {
                            patch(item.key, { error: error.message });
                        }
                    });
            });
        },
        [limits, target.task_id, target.agent_id, target.mailbox_id], // eslint-disable-line react-hooks/exhaustive-deps
    );

    const remove = useCallback((key: string) => {
        const item = itemsRef.current.find((candidate) => candidate.key === key);

        if (item) {
            item.controller.abort();
            if (item.id !== null) {
                discardAttachment(item.id);
            }
            if (item.preview) {
                URL.revokeObjectURL(item.preview);
            }
        }

        setItems((current) => current.filter((candidate) => candidate.key !== key));
    }, []);

    /** After the message is sent: the files now belong to it. */
    const clear = useCallback(() => {
        itemsRef.current.forEach((item) => item.preview && URL.revokeObjectURL(item.preview));
        setItems([]);
        setNotice(null);
    }, []);

    useEffect(() => () => itemsRef.current.forEach((item) => item.preview && URL.revokeObjectURL(item.preview)), []);

    return {
        items,
        add,
        remove,
        clear,
        notice,
        dismissNotice: () => setNotice(null),
        uploading: items.some((item) => item.id === null && item.error === null),
        failed: items.some((item) => item.error !== null),
        ids: items.filter((item) => item.id !== null).map((item) => item.id as number),
    };
}
