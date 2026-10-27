import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Archive,
    Bot,
    Download,
    FilePlus2,
    Folder,
    FolderInput,
    FolderOpen,
    FolderPlus,
    LayoutGrid,
    List,
    MoreHorizontal,
    Pencil,
    Search,
    Trash2,
    UserRound,
    X,
} from 'lucide-react';
import { type DragEvent, type FormEvent, type MouseEvent, type ReactNode, useEffect, useRef, useState } from 'react';

import { AgentAvatar } from '@/Components/AgentAvatar';
import { Monogram } from '@/Components/Blocks';
import { ConfirmDialog, FormDialog } from '@/Components/Dialogs';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { ExtensionTag, FileIcon } from '@/Components/FileIcon';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { useCan } from '@/hooks/useCan';
import { Button } from '@/Components/ui/button';
import { Checkbox } from '@/Components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Textarea } from '@/Components/ui/textarea';
import AppLayout from '@/Layouts/AppLayout';
import { ago, bytes, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, Paginated } from '@/types';

export interface GeneratedDocument {
    id: number;
    title: string;
    format: 'docx' | 'pptx' | 'xlsx' | 'pdf';
    format_label: string;
    template: string;
    template_label: string | null;
    filename: string;
    size: number;
    agent: { id: number; name: string; avatar_url?: string | null } | null;
    creator: string | null;
    knowledge_item: { id: number } | null;
    folder?: { id: number; name: string } | null;
    created_at: string;
}

interface FolderSummary {
    id: number;
    name: string;
    count: number;
}

interface Filters {
    q: string;
    format: string | null;
    autor: 'ia' | 'pessoas' | null;
    pasta: string | null;
    ordem: 'recentes' | 'antigos' | 'nome' | 'tamanho' | 'tipo';
}

interface Props {
    documents: Paginated<GeneratedDocument>;
    filters: Filters;
    folders: FolderSummary[];
    total: number;
    unfiled: number;
    formats: Option[];
    templates: Option[];
    /** Loaded when the person archives files in the knowledge base. */
    knowledge?: { domains: { id: number; name: string }[]; folders: { id: number; domain_id: number; path: string }[] };
}

const placeholder = `# Proposta de manutenção

## Âmbito

- Visita mensal às instalações
- Relatório com fotografias

## Valores

| Serviço | Valor (MZN) |
|---|---|
| Manutenção mensal | 45 000,00 |`;

const sorts: { value: Filters['ordem']; label: string }[] = [
    { value: 'recentes', label: 'Mais recentes' },
    { value: 'antigos', label: 'Mais antigos' },
    { value: 'nome', label: 'Nome (A-Z)' },
    { value: 'tamanho', label: 'Tamanho' },
    { value: 'tipo', label: 'Tipo' },
];

const VIEW_KEY = 'documents.view';

/** Who made a file: an agent (its face and an "IA" mark) or a person. */
export function DocumentAuthor({ document, compact = false }: { document: GeneratedDocument; compact?: boolean }) {
    return document.agent ? (
        <span className="inline-flex min-w-0 items-center gap-1.5" title={`Gerado por IA: ${document.agent.name}`}>
            <AgentAvatar name={document.agent.name} url={document.agent.avatar_url} className="size-5 rounded-md text-[8px]" />
            <span className="truncate">{document.agent.name}</span>
            {!compact && (
                <span className="inline-flex shrink-0 items-center gap-0.5 rounded bg-primary/10 px-1 text-[10px] font-medium text-primary">
                    <Bot className="size-3" />
                    IA
                </span>
            )}
        </span>
    ) : (
        <span className="inline-flex min-w-0 items-center gap-1.5" title={`Feito por ${document.creator ?? 'uma pessoa'}`}>
            <Monogram name={document.creator ?? '?'} className="size-5 rounded-full text-[8px]" />
            <span className="truncate">{document.creator ?? '—'}</span>
            {!compact && (
                <span className="inline-flex shrink-0 items-center gap-0.5 rounded bg-muted px-1 text-[10px] font-medium text-muted-foreground">
                    <UserRound className="size-3" />
                    Pessoa
                </span>
            )}
        </span>
    );
}

export default function DocumentsIndex({ documents, filters, folders, total, unfiled, formats, templates, knowledge }: Props) {
    const [creating, setCreating] = useState(false);
    const can = useCan();
    const manage = can('documents.create');
    const form = useForm({ title: '', subtitle: '', format: 'docx', template: 'documento', content: '' });
    const [view, setView] = useState<'grelha' | 'lista'>(() => {
        try {
            return localStorage.getItem(VIEW_KEY) === 'lista' ? 'lista' : 'grelha';
        } catch {
            return 'grelha';
        }
    });
    const [search, setSearch] = useState(filters.q);
    const [folderForm, setFolderForm] = useState<{ id: number | null; name: string } | null>(null);
    const [folderBusy, setFolderBusy] = useState(false);
    const [folderError, setFolderError] = useState<string | null>(null);
    const [deleting, setDeleting] = useState<FolderSummary | null>(null);
    const [dragging, setDragging] = useState<number | null>(null);
    const [dropOn, setDropOn] = useState<string | null>(null);
    const [selected, setSelected] = useState<number[]>([]);
    const [anchor, setAnchor] = useState<number | null>(null);
    const [bulkDeleting, setBulkDeleting] = useState(false);
    const [filing, setFiling] = useState<{ domain_id: string; folder_id: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const selecting = selected.length > 0;
    const ids = documents.data.map((d) => d.id);
    const all = ids.length > 0 && ids.every((id) => selected.includes(id));

    // Another page, search or folder keeps only the chosen files still on screen.
    useEffect(() => {
        setSelected((current) => current.filter((id) => ids.includes(id)));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [documents.data]);

    // Esc lets go of the selection; Ctrl/Cmd+A takes every file of the page.
    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            const typing = event.target instanceof HTMLElement && event.target.closest('input, textarea, select, [contenteditable], [role="dialog"]');
            if (typing) {
                return;
            }
            if (event.key === 'Escape' && selected.length > 0) {
                setSelected([]);
            } else if (event.key.toLowerCase() === 'a' && (event.metaKey || event.ctrlKey) && ids.length > 0) {
                event.preventDefault();
                setSelected(ids);
            }
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [selected, ids]);

    /** A click on a file's box; with Shift, every file between it and the last one clicked. */
    const toggle = (id: number, range: boolean) => {
        if (range && anchor !== null && ids.includes(anchor)) {
            const [from, to] = [ids.indexOf(anchor), ids.indexOf(id)].sort((a, b) => a - b);
            const span = ids.slice(from, to + 1);
            setSelected((current) => [...new Set([...current, ...span])]);
        } else {
            setSelected((current) => (current.includes(id) ? current.filter((x) => x !== id) : [...current, id]));
        }
        setAnchor(id);
    };

    // While files are chosen, a click on a file adds or removes it instead of opening it.
    const pick = (id: number) => (event: MouseEvent) => {
        if (selecting || event.metaKey || event.ctrlKey || event.shiftKey) {
            event.preventDefault();
            toggle(id, event.shiftKey);
        }
    };

    const bulk = {
        move: (folderId: number | null, chosen: number[] = selected) =>
            router.patch('/documents/bulk/folder', { ids: chosen, folder_id: folderId }, { preserveScroll: true, onSuccess: () => setSelected([]) }),
        download: () => {
            window.location.href = `/documents/bulk/download?${selected.map((id) => `ids[]=${id}`).join('&')}`;
        },
        archive: () => {
            setFiling({ domain_id: '', folder_id: '' });
            router.reload({
                only: ['knowledge'],
                onSuccess: (page) => {
                    const first = (page.props as unknown as Props).knowledge?.domains[0];
                    setFiling((current) => current && { ...current, domain_id: current.domain_id || String(first?.id ?? '') });
                },
            });
        },
    };

    const go = (changes: Partial<Filters>) => {
        const next = { ...filters, ...changes };
        router.get(
            '/documents',
            Object.fromEntries(
                Object.entries(next).filter(([key, value]) => value !== null && value !== '' && !(key === 'ordem' && value === 'recentes')),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    // Search as the person types, a moment after the last key.
    const first = useRef(true);
    useEffect(() => {
        if (first.current) {
            first.current = false;
            return;
        }
        const timer = window.setTimeout(() => go({ q: search.trim() }), 300);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const setLayout = (next: 'grelha' | 'lista') => {
        setView(next);
        try {
            localStorage.setItem(VIEW_KEY, next);
        } catch {
            // The choice is a convenience; without storage it lasts the visit.
        }
    };

    const move = (document: GeneratedDocument, folderId: number | null) =>
        router.patch(`/documents/${document.id}/folder`, { folder_id: folderId }, { preserveScroll: true });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post('/documents');
    };

    // A file dragged onto a folder of the left column goes into it.
    const dropTarget = (key: string, folderId: number | null) =>
        manage
            ? {
                  onDragOver: (event: DragEvent) => {
                      if (dragging !== null) {
                          event.preventDefault();
                          event.dataTransfer.dropEffect = 'move';
                      }
                  },
                  onDragEnter: () => dragging !== null && setDropOn(key),
                  onDragLeave: () => setDropOn((current) => (current === key ? null : current)),
                  onDrop: (event: DragEvent) => {
                      event.preventDefault();
                      const document = documents.data.find((d) => d.id === dragging);
                      if (document && selected.includes(document.id) && selected.length > 1) {
                          bulk.move(folderId);
                      } else if (document && (document.folder?.id ?? null) !== folderId) {
                          move(document, folderId);
                      }
                      setDragging(null);
                      setDropOn(null);
                  },
              }
            : {};

    const draggable = (document: GeneratedDocument) =>
        manage
            ? {
                  draggable: true,
                  onDragStart: (event: DragEvent) => {
                      event.dataTransfer.effectAllowed = 'move';
                      event.dataTransfer.setData('text/plain', String(document.id));
                      setDragging(document.id);
                  },
                  onDragEnd: () => {
                      setDragging(null);
                      setDropOn(null);
                  },
              }
            : {};

    const current = filters.pasta === null ? null : filters.pasta === 'sem' ? 'sem' : folders.find((f) => String(f.id) === filters.pasta);
    const heading = current === null ? 'Todos os ficheiros' : current === 'sem' ? 'Sem pasta' : (current?.name ?? 'Pasta');
    const filtered = filters.q !== '' || filters.format !== null || filters.autor !== null;

    const actions = (document: GeneratedDocument) => (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon" className="size-7 shrink-0" aria-label={`Acções de «${document.title}»`}>
                    <MoreHorizontal />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem asChild>
                    <Link href={`/documents/${document.id}`}>
                        <FolderOpen />
                        Abrir
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem onSelect={() => (window.location.href = `/documents/${document.id}/download`)}>
                    <Download />
                    Descarregar
                </DropdownMenuItem>
                {manage && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuSub>
                            <DropdownMenuSubTrigger>
                                <FolderInput />
                                Mover para
                            </DropdownMenuSubTrigger>
                            <DropdownMenuSubContent>
                                {folders.map((folder) => (
                                    <DropdownMenuItem
                                        key={folder.id}
                                        disabled={document.folder?.id === folder.id}
                                        onSelect={() => move(document, folder.id)}
                                    >
                                        <Folder />
                                        {folder.name}
                                    </DropdownMenuItem>
                                ))}
                                {folders.length > 0 && <DropdownMenuSeparator />}
                                <DropdownMenuItem disabled={!document.folder} onSelect={() => move(document, null)}>
                                    Tirar da pasta
                                </DropdownMenuItem>
                                <DropdownMenuItem onSelect={() => setFolderForm({ id: null, name: '' })}>
                                    <FolderPlus />
                                    Nova pasta…
                                </DropdownMenuItem>
                            </DropdownMenuSubContent>
                        </DropdownMenuSub>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );

    return (
        <AppLayout>
            <Head title="Ficheiros" />
            <PageHeader
                title="Ficheiros"
                description="Documentos Word, apresentações, folhas de cálculo e PDFs gerados pelos agentes ou por pessoas, com a marca da organização."
                actions={
                    manage && (
                        <>
                            <Button variant="outline" onClick={() => setFolderForm({ id: null, name: '' })}>
                                <FolderPlus />
                                Nova pasta
                            </Button>
                            <Button onClick={() => setCreating(true)}>
                                <FilePlus2 />
                                Novo ficheiro
                            </Button>
                        </>
                    )
                }
            />

            <div className="grid gap-6 lg:grid-cols-[13rem_minmax(0,1fr)]">
                <nav aria-label="Pastas" className="flex flex-col gap-0.5 lg:sticky lg:top-4 lg:self-start">
                    <FolderLink active={filters.pasta === null} count={total} onClick={() => go({ pasta: null })} icon={<FolderOpen />}>
                        Todos
                    </FolderLink>
                    <FolderLink
                        active={filters.pasta === 'sem'}
                        count={unfiled}
                        onClick={() => go({ pasta: 'sem' })}
                        icon={<Folder />}
                        drop={dropOn === 'sem'}
                        {...dropTarget('sem', null)}
                    >
                        Sem pasta
                    </FolderLink>
                    {folders.length > 0 && <p className="mt-3 mb-1 px-2.5 text-[11px] font-medium text-muted-foreground">Pastas</p>}
                    {folders.map((folder) => (
                        <FolderLink
                            key={folder.id}
                            active={filters.pasta === String(folder.id)}
                            count={folder.count}
                            onClick={() => go({ pasta: String(folder.id) })}
                            icon={<Folder />}
                            drop={dropOn === `f${folder.id}`}
                            {...dropTarget(`f${folder.id}`, folder.id)}
                            menu={
                                manage && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <button
                                                type="button"
                                                className="inline-flex size-6 items-center justify-center rounded text-muted-foreground opacity-0 group-hover/folder:opacity-100 hover:bg-accent focus-visible:opacity-100"
                                                aria-label={`Acções da pasta «${folder.name}»`}
                                            >
                                                <MoreHorizontal className="size-3.5" />
                                            </button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="start">
                                            <DropdownMenuItem onSelect={() => setFolderForm({ id: folder.id, name: folder.name })}>
                                                <Pencil />
                                                Mudar o nome
                                            </DropdownMenuItem>
                                            <DropdownMenuItem className="text-status-danger" onSelect={() => setDeleting(folder)}>
                                                <Trash2 />
                                                Apagar a pasta
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )
                            }
                        >
                            {folder.name}
                        </FolderLink>
                    ))}
                    {manage && dragging !== null && (
                        <p className="mt-2 px-2.5 text-[11px] text-muted-foreground">
                            {selected.includes(dragging) && selected.length > 1
                                ? `Largue os ${selected.length} ficheiros numa pasta.`
                                : 'Largue o ficheiro numa pasta.'}
                        </p>
                    )}
                </nav>

                <div className="flex min-w-0 flex-col gap-4">
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="flex h-9 min-w-56 flex-1 items-center gap-2 rounded-md border bg-card px-3 text-sm focus-within:ring-2 focus-within:ring-ring/50">
                            <Search className="size-4 shrink-0 text-muted-foreground" />
                            <input
                                type="search"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                                placeholder="Procurar por nome do ficheiro"
                                aria-label="Procurar ficheiros"
                                className="min-w-0 flex-1 bg-transparent outline-none placeholder:text-muted-foreground"
                            />
                        </label>
                        <NativeSelect
                            aria-label="Tipo de ficheiro"
                            className="h-9 w-auto text-sm"
                            value={filters.format ?? ''}
                            onChange={(event) => go({ format: event.target.value || null })}
                        >
                            <option value="">Todos os tipos</option>
                            {formats.map((format) => (
                                <option key={format.value} value={format.value}>
                                    {format.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <div className="flex h-9 items-center rounded-md border bg-card p-0.5" role="group" aria-label="Quem fez">
                            {(
                                [
                                    [null, 'Todos'],
                                    ['ia', 'IA'],
                                    ['pessoas', 'Pessoas'],
                                ] as const
                            ).map(([value, label]) => (
                                <button
                                    key={label}
                                    type="button"
                                    aria-pressed={filters.autor === value}
                                    onClick={() => go({ autor: value })}
                                    className={cn(
                                        'inline-flex h-full items-center gap-1 rounded px-2.5 text-xs transition-colors',
                                        filters.autor === value
                                            ? 'bg-accent font-medium text-foreground'
                                            : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {value === 'ia' && <Bot className="size-3.5" />}
                                    {value === 'pessoas' && <UserRound className="size-3.5" />}
                                    {label}
                                </button>
                            ))}
                        </div>
                        <NativeSelect
                            aria-label="Ordenar"
                            className="h-9 w-auto text-sm"
                            value={filters.ordem}
                            onChange={(event) => go({ ordem: event.target.value as Filters['ordem'] })}
                        >
                            {sorts.map((sort) => (
                                <option key={sort.value} value={sort.value}>
                                    {sort.label}
                                </option>
                            ))}
                        </NativeSelect>
                        <div className="flex h-9 items-center rounded-md border bg-card p-0.5" role="group" aria-label="Vista">
                            {(
                                [
                                    ['grelha', LayoutGrid, 'Grelha'],
                                    ['lista', List, 'Lista'],
                                ] as const
                            ).map(([value, Icon, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    aria-pressed={view === value}
                                    aria-label={label}
                                    title={label}
                                    onClick={() => setLayout(value)}
                                    className={cn(
                                        'inline-flex h-full items-center rounded px-2 transition-colors',
                                        view === value ? 'bg-accent text-foreground' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    <Icon className="size-4" />
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="sticky top-12 z-20 -mx-1 flex min-h-9 flex-wrap items-center gap-x-2 gap-y-1 bg-background/95 px-1 py-1 backdrop-blur">
                        {documents.data.length > 0 && (
                            <Checkbox
                                checked={all ? true : selecting ? 'indeterminate' : false}
                                onCheckedChange={() => setSelected(all ? [] : ids)}
                                aria-label={all ? 'Desmarcar todos' : 'Seleccionar todos os ficheiros da página'}
                                title={all ? 'Desmarcar todos' : 'Seleccionar todos'}
                                className="mr-1"
                            />
                        )}
                        {selecting ? (
                            <>
                                <span className="mr-2 text-sm font-semibold tabular-nums">
                                    {selected.length} {selected.length === 1 ? 'seleccionado' : 'seleccionados'}
                                </span>
                                {manage && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button variant="ghost" size="sm">
                                                <FolderInput />
                                                Mover para
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="start">
                                            {folders.map((folder) => (
                                                <DropdownMenuItem key={folder.id} onSelect={() => bulk.move(folder.id)}>
                                                    <Folder />
                                                    {folder.name}
                                                </DropdownMenuItem>
                                            ))}
                                            {folders.length > 0 && <DropdownMenuSeparator />}
                                            <DropdownMenuItem onSelect={() => bulk.move(null)}>Tirar da pasta</DropdownMenuItem>
                                            <DropdownMenuItem onSelect={() => setFolderForm({ id: null, name: '' })}>
                                                <FolderPlus />
                                                Nova pasta…
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                                <Button variant="ghost" size="sm" onClick={bulk.download}>
                                    <Download />
                                    Descarregar
                                </Button>
                                {manage && (
                                    <Button variant="ghost" size="sm" onClick={bulk.archive}>
                                        <Archive />
                                        Arquivar no conhecimento
                                    </Button>
                                )}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    className="text-status-danger hover:text-status-danger"
                                    onClick={() => setBulkDeleting(true)}
                                >
                                    <Trash2 />
                                    Apagar
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="ml-auto size-8"
                                    onClick={() => setSelected([])}
                                    aria-label="Limpar a selecção"
                                    title="Limpar (Esc)"
                                >
                                    <X />
                                </Button>
                            </>
                        ) : (
                            <>
                                <h2 className="text-sm font-semibold">{heading}</h2>
                                <span className="ml-auto text-xs text-muted-foreground">
                                    {documents.total} {documents.total === 1 ? 'ficheiro' : 'ficheiros'}
                                </span>
                            </>
                        )}
                    </div>

                    {documents.data.length === 0 ? (
                        filtered || filters.pasta !== null ? (
                            <EmptyState
                                icon={Search}
                                title="Nenhum ficheiro aqui"
                                description={
                                    filtered
                                        ? 'Nada corresponde à pesquisa e aos filtros.'
                                        : 'Arraste ficheiros para esta pasta, ou use «Mover para».'
                                }
                            />
                        ) : (
                            <EmptyState
                                icon={FolderOpen}
                                title="Ainda não há ficheiros"
                                description="Peça a um agente “prepara uma apresentação sobre…” ou crie um ficheiro a partir de texto em markdown."
                                action={
                                    manage && (
                                        <Button variant="outline" size="sm" onClick={() => setCreating(true)}>
                                            Novo ficheiro
                                        </Button>
                                    )
                                }
                            />
                        )
                    ) : view === 'grelha' ? (
                        <ul className="grid grid-cols-[repeat(auto-fill,minmax(13rem,1fr))] gap-3">
                            {documents.data.map((d) => (
                                <li
                                    key={d.id}
                                    {...draggable(d)}
                                    className={cn(
                                        'group/card relative flex flex-col overflow-hidden rounded-xl border bg-card shadow-xs transition-[box-shadow,opacity] hover:shadow-sm',
                                        (dragging === d.id || (dragging !== null && selected.includes(dragging) && selected.includes(d.id))) &&
                                            'opacity-50',
                                        selected.includes(d.id) && 'border-primary ring-2 ring-primary/40',
                                    )}
                                >
                                    <Link href={`/documents/${d.id}`} onClick={pick(d.id)} className="absolute inset-0 z-0" aria-label={d.title} />
                                    <Checkbox
                                        checked={selected.includes(d.id)}
                                        onClick={(event) => {
                                            event.preventDefault();
                                            toggle(d.id, event.shiftKey);
                                        }}
                                        aria-label={`Seleccionar «${d.title}»`}
                                        className={cn(
                                            'absolute top-2.5 left-2.5 z-10 size-5 bg-background transition-opacity',
                                            !selecting && 'opacity-0 group-hover/card:opacity-100 focus-visible:opacity-100',
                                        )}
                                    />
                                    <div className="flex h-24 items-center justify-center bg-muted/40">
                                        <FileIcon extension={d.format} className="size-12 rounded-xl [&_svg]:size-6" />
                                    </div>
                                    <div className="flex flex-1 flex-col gap-2 p-3">
                                        <div className="flex items-start gap-1">
                                            <p className="line-clamp-2 min-w-0 flex-1 text-sm leading-snug font-medium">{d.title}</p>
                                            <span className="relative z-10">{actions(d)}</span>
                                        </div>
                                        <div className="mt-auto flex flex-col gap-1.5 text-xs text-muted-foreground">
                                            <DocumentAuthor document={d} />
                                            <span className="flex items-center gap-1.5">
                                                <ExtensionTag extension={d.format} />
                                                <span className="tabular-nums">{bytes(d.size)}</span>
                                                <span className="ml-auto" title={dateTime(d.created_at)}>
                                                    {ago(d.created_at)}
                                                </span>
                                            </span>
                                            {filters.pasta === null && d.folder && (
                                                <span className="flex items-center gap-1 truncate">
                                                    <Folder className="size-3 shrink-0" />
                                                    {d.folder.name}
                                                </span>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <div className="@container overflow-hidden rounded-xl border bg-card">
                            <table className="w-full table-fixed text-sm">
                                <thead className="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                                    <tr>
                                        <th className="w-10 py-2 pl-3">
                                            <span className="sr-only">Seleccionar</span>
                                        </th>
                                        <th className="px-3 py-2 font-medium">Nome</th>
                                        <th className="hidden w-52 px-3 py-2 font-medium @xl:table-cell">Feito por</th>
                                        <th className="hidden w-32 px-3 py-2 font-medium @4xl:table-cell">Pasta</th>
                                        <th className="hidden w-20 px-3 py-2 text-right font-medium @lg:table-cell">Tamanho</th>
                                        <th className="w-24 px-3 py-2 text-right font-medium">Data</th>
                                        <th className="w-10" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {documents.data.map((d) => (
                                        <tr
                                            key={d.id}
                                            {...draggable(d)}
                                            aria-selected={selected.includes(d.id)}
                                            className={cn(
                                                'group/row hover:bg-accent/40',
                                                selected.includes(d.id) && 'bg-primary/5 hover:bg-primary/10',
                                                (dragging === d.id ||
                                                    (dragging !== null && selected.includes(dragging) && selected.includes(d.id))) &&
                                                    'opacity-50',
                                            )}
                                        >
                                            <td className="py-2 pl-3">
                                                <Checkbox
                                                    checked={selected.includes(d.id)}
                                                    onClick={(event) => {
                                                        event.preventDefault();
                                                        toggle(d.id, event.shiftKey);
                                                    }}
                                                    aria-label={`Seleccionar «${d.title}»`}
                                                    className={cn(
                                                        'transition-opacity',
                                                        !selecting && 'opacity-40 group-hover/row:opacity-100 focus-visible:opacity-100',
                                                    )}
                                                />
                                            </td>
                                            <td className="px-3 py-2">
                                                <Link href={`/documents/${d.id}`} onClick={pick(d.id)} className="flex min-w-0 items-center gap-2.5">
                                                    <FileIcon extension={d.format} />
                                                    <span className="min-w-0 flex-1">
                                                        <span className="block truncate font-medium">{d.title}</span>
                                                        <span className="block truncate font-mono text-[11px] text-muted-foreground">
                                                            {d.filename}
                                                        </span>
                                                    </span>
                                                </Link>
                                            </td>
                                            <td className="hidden px-3 py-2 text-xs text-muted-foreground @xl:table-cell">
                                                <DocumentAuthor document={d} />
                                            </td>
                                            <td className="hidden truncate px-3 py-2 text-xs text-muted-foreground @4xl:table-cell">
                                                {d.folder?.name ?? '—'}
                                            </td>
                                            <td className="hidden px-3 py-2 text-right font-mono text-xs text-muted-foreground tabular-nums @lg:table-cell">
                                                {bytes(d.size)}
                                            </td>
                                            <td
                                                className="px-3 py-2 text-right text-xs whitespace-nowrap text-muted-foreground"
                                                title={dateTime(d.created_at)}
                                            >
                                                {ago(d.created_at)}
                                            </td>
                                            <td className="px-1 py-2">{actions(d)}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                    <Pagination page={documents} noun={['ficheiro', 'ficheiros']} />
                </div>
            </div>

            <FormDialog
                open={folderForm !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setFolderForm(null);
                        setFolderError(null);
                    }
                }}
                title={folderForm?.id ? 'Mudar o nome da pasta' : 'Nova pasta'}
                submitLabel={folderForm?.id ? 'Guardar' : 'Criar pasta'}
                processing={folderBusy}
                onSubmit={() => {
                    if (!folderForm) {
                        return;
                    }
                    const options = {
                        preserveScroll: true,
                        onStart: () => setFolderBusy(true),
                        onSuccess: () => setFolderForm(null),
                        onError: (errors: Record<string, string>) => setFolderError(errors.name ?? null),
                        onFinish: () => setFolderBusy(false),
                    };
                    if (folderForm.id) {
                        router.put(`/documents/folders/${folderForm.id}`, { name: folderForm.name }, options);
                    } else {
                        router.post('/documents/folders', { name: folderForm.name }, options);
                    }
                }}
            >
                <Field id="folder-name" label="Nome" error={folderError ?? undefined}>
                    <Input
                        id="folder-name"
                        autoFocus
                        required
                        maxLength={120}
                        value={folderForm?.name ?? ''}
                        onChange={(event) => setFolderForm((current) => current && { ...current, name: event.target.value })}
                        placeholder="Por exemplo: Propostas 2026"
                    />
                </Field>
            </FormDialog>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Apagar a pasta «${deleting?.name ?? ''}»?`}
                description="Os ficheiros não são apagados: voltam para «Todos», sem pasta."
                confirmLabel="Apagar a pasta"
                destructive
                onConfirm={() => deleting && router.delete(`/documents/folders/${deleting.id}`, { onFinish: () => setDeleting(null) })}
            />

            <ConfirmDialog
                open={bulkDeleting}
                onOpenChange={setBulkDeleting}
                title={selected.length === 1 ? 'Apagar 1 ficheiro?' : `Apagar ${selected.length} ficheiros?`}
                description="Os ficheiros saem de Ficheiros e não se recuperam. O que já foi arquivado na base de conhecimento fica lá."
                confirmLabel="Apagar"
                destructive
                processing={busy}
                onConfirm={() =>
                    router.delete('/documents/bulk', {
                        data: { ids: selected },
                        preserveScroll: true,
                        onStart: () => setBusy(true),
                        onSuccess: () => setSelected([]),
                        onFinish: () => {
                            setBusy(false);
                            setBulkDeleting(false);
                        },
                    })
                }
            />

            <FormDialog
                open={filing !== null}
                onOpenChange={(open) => !open && setFiling(null)}
                title="Arquivar na base de conhecimento"
                description={`${selected.length === 1 ? 'O ficheiro passa' : `Os ${selected.length} ficheiros passam`} a estar disponíveis para os agentes. Os que já lá estão ficam como estão.`}
                submitLabel="Arquivar"
                processing={busy}
                disabled={!knowledge || !filing?.domain_id}
                onSubmit={() =>
                    filing &&
                    router.post(
                        '/documents/bulk/file',
                        { ids: selected, domain_id: filing.domain_id, folder_id: filing.folder_id || null },
                        {
                            preserveScroll: true,
                            onStart: () => setBusy(true),
                            onSuccess: () => {
                                setFiling(null);
                                setSelected([]);
                            },
                            onFinish: () => setBusy(false),
                        },
                    )
                }
            >
                <Field id="bulk-domain" label="Domínio">
                    <NativeSelect
                        id="bulk-domain"
                        value={filing?.domain_id ?? ''}
                        onChange={(event) => setFiling((current) => current && { domain_id: event.target.value, folder_id: '' })}
                        disabled={!knowledge}
                    >
                        {!knowledge && <option value="">A carregar…</option>}
                        {knowledge?.domains.map((domain) => (
                            <option key={domain.id} value={domain.id}>
                                {domain.name}
                            </option>
                        ))}
                    </NativeSelect>
                </Field>
                <Field id="bulk-folder" label="Pasta">
                    <NativeSelect
                        id="bulk-folder"
                        value={filing?.folder_id ?? ''}
                        onChange={(event) => setFiling((current) => current && { ...current, folder_id: event.target.value })}
                        disabled={!knowledge}
                    >
                        <option value="">Raiz do domínio</option>
                        {knowledge?.folders
                            .filter((folder) => String(folder.domain_id) === filing?.domain_id)
                            .map((folder) => (
                                <option key={folder.id} value={folder.id}>
                                    {folder.path}
                                </option>
                            ))}
                    </NativeSelect>
                </Field>
            </FormDialog>

            <Dialog open={creating} onOpenChange={setCreating}>
                <DialogContent className="sm:max-w-2xl">
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>Novo ficheiro</DialogTitle>
                            <DialogDescription>
                                Escreva em markdown. Em apresentações, cada título ## é um diapositivo; em Excel, cada tabela é uma folha.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field id="doc-title" label="Título" error={form.errors.title}>
                                <Input id="doc-title" value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
                            </Field>
                            <Field id="doc-subtitle" label="Subtítulo" error={form.errors.subtitle}>
                                <Input
                                    id="doc-subtitle"
                                    value={form.data.subtitle}
                                    onChange={(e) => form.setData('subtitle', e.target.value)}
                                    placeholder="Opcional"
                                />
                            </Field>
                            <Field id="doc-format" label="Formato" error={form.errors.format}>
                                <NativeSelect id="doc-format" value={form.data.format} onChange={(e) => form.setData('format', e.target.value)}>
                                    {formats.map((f) => (
                                        <option key={f.value} value={f.value}>
                                            {f.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                            <Field id="doc-template" label="Modelo" error={form.errors.template} hint="Word e PDF.">
                                <NativeSelect
                                    id="doc-template"
                                    value={form.data.template}
                                    onChange={(e) => form.setData('template', e.target.value)}
                                    disabled={form.data.format === 'xlsx' || form.data.format === 'pptx'}
                                >
                                    {templates.map((t) => (
                                        <option key={t.value} value={t.value}>
                                            {t.label}
                                        </option>
                                    ))}
                                </NativeSelect>
                            </Field>
                        </div>
                        <Field id="doc-content" label="Conteúdo" error={form.errors.content}>
                            <Textarea
                                id="doc-content"
                                rows={12}
                                className="font-mono text-[13px]"
                                placeholder={placeholder}
                                value={form.data.content}
                                onChange={(e) => form.setData('content', e.target.value)}
                            />
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? 'A gerar…' : 'Gerar'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

/** One line of the folders column; a drop target while a file is dragged. */
function FolderLink({
    active,
    count,
    onClick,
    icon,
    children,
    menu,
    drop = false,
    ...dropProps
}: {
    active: boolean;
    count: number;
    onClick: () => void;
    icon: ReactNode;
    children: ReactNode;
    menu?: ReactNode;
    drop?: boolean;
    onDragOver?: (event: DragEvent) => void;
    onDragEnter?: () => void;
    onDragLeave?: () => void;
    onDrop?: (event: DragEvent) => void;
}) {
    return (
        <div
            {...dropProps}
            className={cn(
                'group/folder flex items-center gap-1 rounded-md pr-1 transition-colors',
                active ? 'bg-accent text-foreground' : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                drop && 'bg-primary/10 text-primary ring-1 ring-primary/40',
            )}
        >
            <button
                type="button"
                onClick={onClick}
                className="flex h-8 min-w-0 flex-1 items-center gap-2 px-2.5 text-left text-sm [&_svg]:size-4 [&_svg]:shrink-0"
            >
                {icon}
                <span className="min-w-0 flex-1 truncate">{children}</span>
                <span className="text-xs tabular-nums opacity-70">{count}</span>
            </button>
            {menu}
        </div>
    );
}
