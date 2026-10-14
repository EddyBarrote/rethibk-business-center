import { router } from '@inertiajs/react';
import { Check, CircleAlert, FolderTree } from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useRef, useState } from 'react';

import { EntityRow, ListPanel } from '@/Components/Blocks';
import { Field } from '@/Components/Field';
import { FileIcon } from '@/Components/FileIcon';
import type { PickedFile } from '@/Components/knowledge/dropped';
import { Button } from '@/Components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { NativeSelect } from '@/Components/ui/native-select';
import { Progress } from '@/Components/ui/progress';
import { type AttachmentLimits, uploadAttachment } from '@/lib/attachments';
import { bytes } from '@/lib/format';

export interface RepositoryLimits extends AttachmentLimits {
    max_files: number;
}

interface Row {
    key: string;
    picked: PickedFile;
    problem: string | null;
    progress: number;
    id: number | null;
    error: string | null;
}

const PARALLEL = 3;

/**
 * Files into the repository: each one is sent in parts (so size does not
 * depend on PHP's upload limit) and, when all have arrived, filed in the
 * domain and folder in one request. A dragged folder keeps its folders.
 */
export function UploadDialog({
    picked,
    onClose,
    domains,
    domainId,
    folder,
    limits,
}: {
    picked: PickedFile[];
    onClose: () => void;
    domains: { id: number; name: string }[];
    domainId: number | null;
    folder: { id: number; domain_id: number; path: string } | null;
    limits: RepositoryLimits;
}) {
    const [domain, setDomain] = useState(String(domainId ?? domains[0]?.id ?? ''));
    const [rows, setRows] = useState<Row[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const controller = useRef<AbortController | null>(null);

    useEffect(() => {
        setDomain(String(domainId ?? domains[0]?.id ?? ''));
        setError(null);
        setRows(
            picked.slice(0, limits.max_files).map((p, index) => {
                const extension = p.file.name.split('.').pop()?.toLowerCase() ?? '';

                return {
                    key: `${index}-${p.path ?? p.file.name}`,
                    picked: p,
                    problem: !limits.extensions.includes(extension)
                        ? 'tipo não aceite'
                        : p.file.size > limits.max_mb * 1024 * 1024
                          ? `passa de ${limits.max_mb} MB`
                          : p.file.size === 0
                            ? 'vazio'
                            : null,
                    progress: 0,
                    id: null,
                    error: null,
                };
            }),
        );
    }, [picked]); // eslint-disable-line react-hooks/exhaustive-deps

    const good = rows.filter((row) => row.problem === null);
    const folders = useMemo(
        () => [...new Set(rows.map((row) => row.picked.path?.split('/').slice(0, -1).join(' / ')).filter((path): path is string => !!path))],
        [rows],
    );
    const inFolder = folder !== null && String(folder.domain_id) === domain ? folder : null;
    const total = good.reduce((sum, row) => sum + row.picked.file.size, 0);
    const sent = good.reduce((sum, row) => sum + row.picked.file.size * row.progress, 0);

    const patch = (key: string, change: Partial<Row>) => setRows((current) => current.map((row) => (row.key === key ? { ...row, ...change } : row)));

    const close = () => {
        controller.current?.abort();
        setBusy(false);
        onClose();
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setBusy(true);
        setError(null);
        controller.current = new AbortController();
        const signal = controller.current.signal;
        const queue = [...good.filter((row) => row.id === null)];
        const done: { id: number; path: string | null }[] = good.filter((row) => row.id !== null).map((row) => ({ id: row.id as number, path: row.picked.path }));

        const worker = async () => {
            for (let row = queue.shift(); row; row = queue.shift()) {
                const current = row;

                try {
                    const { id } = await uploadAttachment(
                        current.picked.file,
                        {},
                        limits.chunk_bytes,
                        (progress) => patch(current.key, { progress }),
                        signal,
                        'repository',
                    );
                    patch(current.key, { id, progress: 1 });
                    done.push({ id, path: current.picked.path });
                } catch (e) {
                    if ((e as Error).name === 'AbortError') {
                        return;
                    }

                    patch(current.key, { error: (e as Error).message });
                }
            }
        };

        await Promise.all(Array.from({ length: PARALLEL }, worker));

        if (signal.aborted) {
            return;
        }

        if (done.length === 0) {
            setBusy(false);
            setError('Nenhum ficheiro chegou ao servidor.');

            return;
        }

        router.post(
            '/knowledge/upload',
            { uploads: done, domain_id: Number(domain), folder_id: inFolder?.id ?? null },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setBusy(false);
                    onClose();
                },
                onError: (errors) => {
                    setBusy(false);
                    setError(Object.values(errors)[0] ?? 'Não foi possível guardar.');
                },
            },
        );
    };

    return (
        <Dialog open={picked.length > 0} onOpenChange={(open) => !open && close()}>
            <DialogContent className="sm:max-w-xl">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Carregar {good.length === 1 ? 'ficheiro' : `${good.length} ficheiros`}</DialogTitle>
                        <DialogDescription>
                            O texto de cada ficheiro é lido em segundo plano, página a página; as páginas digitalizadas são lidas por IA. Depois, pessoas
                            e agentes encontram-no na pesquisa.
                        </DialogDescription>
                    </DialogHeader>

                    <ListPanel className="max-h-72 overflow-y-auto">
                        {rows.map((row) => (
                            <EntityRow
                                key={row.key}
                                leading={<FileIcon extension={row.picked.file.name.split('.').pop()?.toLowerCase()} />}
                                title={row.picked.file.name}
                                subtitle={
                                    row.problem ? (
                                        <span className="text-status-danger">Fica de fora: {row.problem}</span>
                                    ) : row.error ? (
                                        <span className="text-status-danger">{row.error}</span>
                                    ) : row.picked.path && row.picked.path.includes('/') ? (
                                        row.picked.path.split('/').slice(0, -1).join(' / ')
                                    ) : undefined
                                }
                                trailing={
                                    row.problem || row.error ? (
                                        <CircleAlert className="size-4 text-status-danger" />
                                    ) : row.id !== null ? (
                                        <Check className="size-4 text-status-success" />
                                    ) : busy ? (
                                        <Progress value={row.progress * 100} className="w-16" />
                                    ) : (
                                        <span className="font-mono text-xs text-muted-foreground">{bytes(row.picked.file.size)}</span>
                                    )
                                }
                            />
                        ))}
                    </ListPanel>
                    {picked.length > limits.max_files && (
                        <p className="text-sm text-status-warning">
                            Só os primeiros {limits.max_files} ficheiros; carregue o resto depois.
                        </p>
                    )}

                    <Field id="upload-domain" label="Domínio">
                        <NativeSelect id="upload-domain" value={domain} disabled={busy} onChange={(e) => setDomain(e.target.value)}>
                            {domains.map((d) => (
                                <option key={d.id} value={d.id}>
                                    {d.name}
                                </option>
                            ))}
                        </NativeSelect>
                    </Field>
                    {(inFolder || folders.length > 0) && (
                        <p className="flex items-start gap-2 text-sm text-muted-foreground">
                            <FolderTree className="mt-0.5 size-4 shrink-0" />
                            <span>
                                {inFolder && (
                                    <>
                                        Na pasta <span className="font-medium text-foreground">{inFolder.path}</span>.{' '}
                                    </>
                                )}
                                {folders.length > 0 && (
                                    <>
                                        Mantém {folders.length === 1 ? 'a pasta' : `as ${folders.length} pastas`}{' '}
                                        <span className="font-medium text-foreground">{folders.slice(0, 3).join(', ')}</span>
                                        {folders.length > 3 ? '…' : ''}.
                                    </>
                                )}
                            </span>
                        </p>
                    )}
                    {busy && (
                        <div className="flex flex-col gap-1.5">
                            <Progress value={total > 0 ? (sent / total) * 100 : 0} />
                            <p className="text-xs text-muted-foreground tabular-nums">
                                {bytes(sent)} de {bytes(total)}
                            </p>
                        </div>
                    )}
                    {error && <p className="text-sm text-destructive">{error}</p>}

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={close}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={busy || good.length === 0 || domain === ''}>
                            {busy ? 'A carregar…' : 'Carregar'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
