import { Link, router } from '@inertiajs/react';
import { FileText, History, RotateCcw } from 'lucide-react';
import { useState } from 'react';

import { ConfirmDialog } from '@/Components/Dialogs';
import { Markdown } from '@/Components/Markdown';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/Components/ui/tabs';
import { ago, bytes, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';

/** One row of a skill's history (docs/DECISOES.md, "Latência, taxa de erro e versões das skills"). */
export interface SkillVersionRow {
    number: number;
    author: string | null;
    created_at: string | null;
    /** The version this one put back, when it was a restore. */
    restored_from: number | null;
    is_current: boolean;
}

export interface SkillVersionDetail extends SkillVersionRow {
    key: string;
    name: string;
    description: string;
    instructions: string;
    files: { filename: string; size: number; has_text: boolean }[];
}

interface DiffLine {
    type: 'same' | 'added' | 'removed';
    text: string;
}

export interface SkillVersionDiff {
    name: DiffLine[];
    description: DiffLine[];
    instructions: DiffLine[];
    files: { filename: string; status: 'added' | 'removed' | 'changed' | 'same'; size: number }[];
    changed: boolean;
}

const who = (version: SkillVersionRow) => version.author ?? 'Sem autor registado';

/**
 * «Versões» beside the skill editor: newest first, each opening its page,
 * where it is compared with the current one and can be put back.
 */
export function SkillVersionList({ base, skillId, versions }: { base: string; skillId: number; versions: SkillVersionRow[] }) {
    const [all, setAll] = useState(false);
    const shown = all ? versions : versions.slice(0, 5);

    return (
        <div className="rounded-xl border bg-card p-4">
            <h2 className="mb-1 flex items-center gap-1.5 text-sm font-semibold">
                <History className="size-4 text-muted-foreground" />
                Versões
            </h2>
            <p className="mb-3 text-xs text-muted-foreground">
                Cada alteração ao texto ou aos ficheiros fica guardada. Os agentes usam sempre a versão actual.
            </p>
            {versions.length === 0 ? (
                <p className="text-sm text-muted-foreground">A primeira versão fica guardada na próxima alteração.</p>
            ) : (
                <ul className="grid gap-0.5">
                    {shown.map((version) => (
                        <li key={version.number}>
                            <Link
                                href={`${base}/${skillId}/versions/${version.number}`}
                                className="flex items-start gap-2 rounded-md px-1.5 py-1.5 text-sm transition-colors hover:bg-muted/60"
                            >
                                <span className="w-8 shrink-0 font-mono text-xs leading-5 text-muted-foreground tabular-nums">v{version.number}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="block truncate">{who(version)}</span>
                                    <span className="block text-xs text-muted-foreground" title={dateTime(version.created_at)}>
                                        {ago(version.created_at)}
                                        {version.restored_from !== null && ` · repôs a v${version.restored_from}`}
                                    </span>
                                </span>
                                {version.is_current && (
                                    <StatusBadge tone="success" dot={false}>
                                        actual
                                    </StatusBadge>
                                )}
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {versions.length > 5 && (
                <Button size="sm" variant="ghost" className="mt-2 w-full" onClick={() => setAll(!all)}>
                    {all ? 'Mostrar menos' : `Ver as ${versions.length} versões`}
                </Button>
            )}
        </div>
    );
}

/**
 * One version: compared line by line with the current skill (the version is
 * the old side), what it held, and «Repor esta versão», which writes it back
 * as a new version.
 */
export function SkillVersionView({
    base,
    skill,
    version,
    diff,
}: {
    base: string;
    skill: { id: number; key: string; name: string };
    version: SkillVersionDetail;
    diff: SkillVersionDiff;
}) {
    const [restoring, setRestoring] = useState(false);
    const restore = () =>
        router.post(
            `${base}/${skill.id}/versions/${version.number}/restore`,
            {},
            { onStart: () => setRestoring(true), onFinish: () => setRestoring(false) },
        );

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-center">
                <div className="min-w-0 flex-1 text-sm">
                    <p className="font-medium">
                        Versão {version.number}
                        {version.is_current && (
                            <StatusBadge tone="success" dot={false} className="ml-2 align-middle">
                                actual
                            </StatusBadge>
                        )}
                    </p>
                    <p className="text-muted-foreground">
                        {who(version)} · {dateTime(version.created_at)}
                        {version.restored_from !== null && ` · repôs a versão ${version.restored_from}`}
                    </p>
                </div>
                <div className="flex items-center gap-2">
                    <Button variant="ghost" asChild>
                        <Link href={`${base}/${skill.id}/edit`}>Voltar à skill</Link>
                    </Button>
                    {!version.is_current && (
                        <ConfirmDialog
                            title={`Repor a versão ${version.number}?`}
                            description="O nome, o «quando usar», as instruções e os ficheiros desta versão voltam a ser os da skill, como uma versão nova. A chave e os agentes que a usam ficam. Nenhuma versão se perde."
                            confirmLabel="Repor esta versão"
                            processing={restoring}
                            onConfirm={restore}
                            trigger={
                                <Button>
                                    <RotateCcw />
                                    Repor esta versão
                                </Button>
                            }
                        />
                    )}
                </div>
            </div>

            <Tabs defaultValue={version.is_current ? 'content' : 'compare'}>
                <TabsList variant="line" className="w-full justify-start border-b pb-1">
                    {!version.is_current && <TabsTrigger value="compare">Comparar com a actual</TabsTrigger>}
                    <TabsTrigger value="content">Esta versão</TabsTrigger>
                </TabsList>

                {!version.is_current && (
                    <TabsContent value="compare" className="flex flex-col gap-5 pt-4">
                        {!diff.changed ? (
                            <p className="rounded-xl border bg-card p-4 text-sm text-muted-foreground">Igual à versão actual.</p>
                        ) : (
                            <>
                                <p className="text-xs text-muted-foreground">
                                    <span className="text-status-danger">−</span> só na versão {version.number} ·{' '}
                                    <span className="text-status-success">+</span> só na actual.
                                </p>
                                <DiffBlock title="Nome" lines={diff.name} />
                                <DiffBlock title="Quando usar" lines={diff.description} />
                                <DiffBlock title="Instruções" lines={diff.instructions} />
                                <FilesChanges files={diff.files} />
                            </>
                        )}
                    </TabsContent>
                )}

                <TabsContent value="content" className="grid gap-6 pt-4 lg:grid-cols-[minmax(0,1fr)_16rem]">
                    <div className="flex min-w-0 flex-col gap-4">
                        <div>
                            <p className="text-xs font-medium text-muted-foreground">Nome</p>
                            <p className="text-sm">{version.name}</p>
                        </div>
                        <div>
                            <p className="text-xs font-medium text-muted-foreground">Quando usar</p>
                            <p className="text-sm whitespace-pre-wrap">{version.description}</p>
                        </div>
                        <div>
                            <p className="mb-1 text-xs font-medium text-muted-foreground">Instruções</p>
                            <div className="rounded-lg border p-4">
                                <Markdown>{version.instructions}</Markdown>
                            </div>
                        </div>
                    </div>
                    <div className="self-start rounded-xl border bg-card p-4">
                        <h2 className="mb-2 text-sm font-semibold">Ficheiros</h2>
                        {version.files.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Sem ficheiros nesta versão.</p>
                        ) : (
                            <ul className="divide-y">
                                {version.files.map((file) => (
                                    <li key={file.filename} className="flex items-center gap-2 py-2">
                                        <FileText className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm">{file.filename}</span>
                                            <span className="font-mono text-[11px] text-muted-foreground">{bytes(file.size)}</span>
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    );
}

/** Unchanged stretches longer than this fold, keeping CONTEXT lines on each side of a change. */
const FOLD = 8;
const CONTEXT = 3;

type Piece = { kind: 'line'; line: DiffLine; old: number | null; new: number | null } | { kind: 'fold'; count: number };

function pieces(lines: DiffLine[]): Piece[] {
    let oldNumber = 0;
    let newNumber = 0;
    const numbered = lines.map((line) => {
        const old = line.type === 'added' ? null : ++oldNumber;
        const next = line.type === 'removed' ? null : ++newNumber;

        return { kind: 'line' as const, line, old, new: next };
    });

    const result: Piece[] = [];
    let run: typeof numbered = [];
    const flush = (atStart: boolean, atEnd: boolean) => {
        const keepBefore = atStart ? 0 : CONTEXT;
        const keepAfter = atEnd ? 0 : CONTEXT;

        if (run.length > FOLD && run.length > keepBefore + keepAfter) {
            result.push(
                ...run.slice(0, keepBefore),
                { kind: 'fold', count: run.length - keepBefore - keepAfter },
                ...run.slice(run.length - keepAfter),
            );
        } else {
            result.push(...run);
        }
        run = [];
    };

    numbered.forEach((piece) => {
        if (piece.line.type === 'same') {
            run.push(piece);

            return;
        }
        flush(result.length === 0, false);
        result.push(piece);
    });
    flush(result.length === 0, true);

    return result;
}

function DiffBlock({ title, lines }: { title: string; lines: DiffLine[] }) {
    if (!lines.some((line) => line.type !== 'same')) {
        return null;
    }

    return (
        <section className="flex flex-col gap-1.5">
            <h3 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">{title}</h3>
            <div className="overflow-x-auto rounded-lg border font-mono text-xs leading-5">
                {pieces(lines).map((piece, index) =>
                    piece.kind === 'fold' ? (
                        <div key={index} className="bg-muted/40 px-3 py-0.5 text-muted-foreground">
                            … {piece.count} linhas iguais
                        </div>
                    ) : (
                        <div
                            key={index}
                            className={cn(
                                'flex min-w-fit gap-3 px-3 whitespace-pre',
                                piece.line.type === 'added' && 'bg-status-success/10',
                                piece.line.type === 'removed' && 'bg-status-danger/10',
                            )}
                        >
                            <span className="w-8 shrink-0 text-right text-muted-foreground/70 select-none">{piece.old ?? ''}</span>
                            <span className="w-8 shrink-0 text-right text-muted-foreground/70 select-none">{piece.new ?? ''}</span>
                            <span
                                className={cn(
                                    'w-3 shrink-0 select-none',
                                    piece.line.type === 'added' && 'text-status-success',
                                    piece.line.type === 'removed' && 'text-status-danger',
                                )}
                            >
                                {piece.line.type === 'added' ? '+' : piece.line.type === 'removed' ? '−' : ''}
                            </span>
                            <span>{piece.line.text === '' ? ' ' : piece.line.text}</span>
                        </div>
                    ),
                )}
            </div>
        </section>
    );
}

const fileStatus = {
    added: { label: 'só na actual', tone: 'success' },
    removed: { label: 'só nesta versão', tone: 'danger' },
    changed: { label: 'mudou', tone: 'warning' },
} as const;

function FilesChanges({ files }: { files: SkillVersionDiff['files'] }) {
    const changed = files.filter((file) => file.status !== 'same');

    if (changed.length === 0) {
        return null;
    }

    return (
        <section className="flex flex-col gap-1.5">
            <h3 className="text-xs font-medium tracking-widest text-muted-foreground uppercase">Ficheiros</h3>
            <ul className="divide-y rounded-lg border">
                {changed.map((file) => {
                    const status = fileStatus[file.status as keyof typeof fileStatus];

                    return (
                        <li key={file.filename} className="flex items-center gap-2 px-3 py-2">
                            <FileText className="size-4 shrink-0 text-muted-foreground" />
                            <span className="min-w-0 flex-1 truncate text-sm">{file.filename}</span>
                            <span className="font-mono text-[11px] text-muted-foreground">{bytes(file.size)}</span>
                            <StatusBadge tone={status.tone} dot={false}>
                                {status.label}
                            </StatusBadge>
                        </li>
                    );
                })}
            </ul>
        </section>
    );
}
