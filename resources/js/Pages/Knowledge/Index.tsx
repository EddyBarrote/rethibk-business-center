import { Head, Link, router, useForm } from '@inertiajs/react';
import { Bot, ChevronDown, ChevronRight, Clock, Files, Folder, FolderPlus, FolderUp, Inbox, LayoutGrid, Library, List, Lock, NotebookPen, Search, Settings2, Upload } from 'lucide-react';
import { type DragEvent, type FormEvent, useRef, useState } from 'react';

import { EntityRow, ListPanel, Section } from '@/Components/Blocks';
import { EmptyState } from '@/Components/EmptyState';
import { Field } from '@/Components/Field';
import { FileIcon } from '@/Components/FileIcon';
import { fromDrop, fromInput, type PickedFile } from '@/Components/knowledge/dropped';
import { FileCard, ReadingBadge } from '@/Components/knowledge/FileCard';
import { type RepositoryLimits, UploadDialog } from '@/Components/knowledge/UploadDialog';
import { PageHeader } from '@/Components/PageHeader';
import { StatusBadge } from '@/Components/Status';
import { Button } from '@/Components/ui/button';
import { PaginationBar, usePaged } from '@/Components/Pagination';
import { useCan } from '@/hooks/useCan';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/Components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/Components/ui/dropdown-menu';
import { Input } from '@/Components/ui/input';
import AppLayout from '@/Layouts/AppLayout';
import { ago, dateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { type DomainSummary, indexState, type KnowledgeRow, reviewState } from '@/Pages/Knowledge/types';

interface Props {
    domains: (DomainSummary & { items: number })[];
    domain: (DomainSummary & { can_curate: boolean }) | null;
    folder: { id: number; name: string; parent_id: number | null; path: { id: number; name: string }[] } | null;
    folders: { id: number; name: string; items: number; children: number }[];
    items: KnowledgeRow[];
    filters: { q: string; view: 'review' | 'files' | null; kind: Kind | null };
    pending: number;
    files: number;
    unfiled: number;
    semantic: boolean;
    accept: string;
    upload: RepositoryLimits;
    can: { manage_domains: boolean };
}

type Kind = 'pdf' | 'office' | 'image' | 'text';

const KINDS: { value: Kind | null; label: string }[] = [
    { value: null, label: 'Tudo' },
    { value: 'pdf', label: 'PDF' },
    { value: 'office', label: 'Word, Excel, PowerPoint' },
    { value: 'image', label: 'Imagens' },
    { value: 'text', label: 'Texto' },
];

const LAYOUT_KEY = 'knowledge.layout';

function savedLayout(): 'list' | 'grid' {
    try {
        return window.localStorage.getItem(LAYOUT_KEY) === 'grid' ? 'grid' : 'list';
    } catch {
        return 'list';
    }
}

export function DomainDot({ color, className }: { color: string | null; className?: string }) {
    return (
        <span
            className={cn('inline-block size-2 shrink-0 rounded-full', className)}
            style={{ backgroundColor: color ?? 'var(--muted-foreground)' }}
        />
    );
}

/** A filter row in the left column: icon or dot, label, count. */
function NavRow({
    href,
    active,
    children,
    count,
    warn,
}: {
    href: string;
    active: boolean;
    children: React.ReactNode;
    count?: number;
    warn?: boolean;
}) {
    return (
        <Link
            href={href}
            preserveState
            className={cn(
                'flex h-8 items-center gap-2 rounded-lg px-2.5 text-sm transition-colors hover:bg-accent/60',
                active ? 'bg-accent font-medium text-foreground' : 'text-foreground/80',
            )}
        >
            {children}
            {count !== undefined && count > 0 && (
                <span className={cn('ml-auto font-mono text-xs tabular-nums', warn ? 'text-status-warning' : 'text-muted-foreground')}>{count}</span>
            )}
        </Link>
    );
}

export default function KnowledgeIndex({ domains, domain, folder, folders, items, filters, pending, files, semantic, accept, upload, can }: Props) {
    const [layout, setLayout] = useState<'list' | 'grid'>(savedLayout);
    const paged = usePaged(items, layout === 'grid' ? 24 : 20);
    const canWrite = useCan();
    const [query, setQuery] = useState(filters.q);
    const [picked, setPicked] = useState<PickedFile[]>([]);
    const [creatingFolder, setCreatingFolder] = useState(false);
    const [dragging, setDragging] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);
    const folderInput = useRef<HTMLInputElement>(null);

    const folderForm = useForm({ name: '', domain_id: '', parent_id: '' });

    const target = domain ?? domains[0] ?? null;

    const search = (event: FormEvent) => {
        event.preventDefault();
        router.get('/knowledge', { ...(query ? { q: query } : {}), ...(domain ? { domain: domain.slug } : {}) }, { preserveState: true });
    };

    const startUpload = (chosen: PickedFile[]) => {
        if (chosen.length === 0 || target === null || !canWrite('knowledge.write')) {
            return;
        }

        setPicked(chosen);
    };

    const switchLayout = (next: 'list' | 'grid') => {
        setLayout(next);

        try {
            window.localStorage.setItem(LAYOUT_KEY, next);
        } catch {
            // Private windows: the choice lasts until the page reloads.
        }
    };

    const kindHref = (kind: Kind | null) => {
        const params = new URLSearchParams();

        if (filters.view === 'files') params.set('view', 'files');
        if (domain && filters.view !== 'files') params.set('domain', domain.slug);
        if (folder && filters.view !== 'files') params.set('folder', String(folder.id));
        if (kind) params.set('kind', kind);

        return `/knowledge${params.size ? `?${params.toString()}` : ''}`;
    };

    const submitFolder = (event: FormEvent) => {
        event.preventDefault();
        folderForm.transform((data) => ({ ...data, domain_id: domain?.id, parent_id: folder?.id ?? null }));
        folderForm.post('/knowledge/folders', {
            onSuccess: () => {
                setCreatingFolder(false);
                folderForm.reset();
            },
        });
    };

    const onDrop = (event: DragEvent) => {
        event.preventDefault();
        setDragging(false);
        void fromDrop(event.dataTransfer).then(startUpload);
    };

    const browsing = filters.q === '' && (filters.view === null || filters.view === 'files');
    const filing = filters.q === '' && filters.view !== 'review';
    const heading = filters.q
        ? `Resultados para “${filters.q}”`
        : filters.view === 'review'
          ? 'Escrito por agentes, à espera de revisão'
          : filters.view === 'files'
            ? 'Todos os ficheiros'
            : domain
            ? folder
                ? folder.name
                : 'Neste domínio'
            : 'Alterados recentemente';
    const newArticle = `/knowledge/new${domain ? `?domain=${domain.slug}${folder ? `&folder=${folder.id}` : ''}` : ''}`;

    return (
        <AppLayout breadcrumbs={domain ? [{ label: 'Conhecimento', href: '/knowledge' }, { label: domain.name }] : undefined}>
            <Head title={domain?.name ?? 'Conhecimento'} />
            <PageHeader
                title="Conhecimento"
                description={`Artigos e ficheiros da organização, por domínio. Pessoas e agentes pesquisam aqui ${semantic ? 'por significado' : 'por palavras'}.`}
                actions={
                    <>
                        {can.manage_domains && (
                            <Button variant="ghost" asChild>
                                <Link href="/knowledge/domains">
                                    <Settings2 />
                                    Domínios
                                </Link>
                            </Button>
                        )}
                        {domain && (
                            <Button variant="outline" onClick={() => setCreatingFolder(true)}>
                                <FolderPlus />
                                Nova pasta
                            </Button>
                        )}
                        {canWrite('knowledge.write') && (
                            <>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button variant="outline" disabled={target === null}>
                                            <Upload />
                                            Carregar
                                            <ChevronDown className="size-3.5 opacity-60" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <DropdownMenuItem onSelect={() => fileInput.current?.click()}>
                                            <Upload />
                                            Ficheiros
                                        </DropdownMenuItem>
                                        <DropdownMenuItem onSelect={() => folderInput.current?.click()}>
                                            <FolderUp />
                                            Uma pasta inteira
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                                <Button asChild>
                                    <Link href={newArticle}>
                                        <NotebookPen />
                                        Novo artigo
                                    </Link>
                                </Button>
                            </>
                        )}
                        <input
                            ref={fileInput}
                            type="file"
                            multiple
                            accept={accept}
                            className="hidden"
                            onChange={(e) => {
                                startUpload(fromInput(e.target.files));
                                e.target.value = '';
                            }}
                        />
                        <input
                            ref={folderInput}
                            type="file"
                            multiple
                            className="hidden"
                            {...{ webkitdirectory: '', directory: '' }}
                            onChange={(e) => {
                                startUpload(fromInput(e.target.files));
                                e.target.value = '';
                            }}
                        />
                    </>
                }
            />

            <div className="grid gap-6 lg:grid-cols-[15rem_minmax(0,1fr)]">
                <nav className="flex flex-col gap-4 lg:sticky lg:top-20 lg:self-start" aria-label="Domínios">
                    <div className="flex flex-col gap-0.5">
                        <NavRow href="/knowledge" active={!domain && filters.q === "" && filters.view === null}>
                            <Clock className="size-4 text-muted-foreground" />
                            Recentes
                        </NavRow>
                        <NavRow href="/knowledge?view=files" active={filters.view === 'files'} count={files}>
                            <Files className="size-4 text-muted-foreground" />
                            Ficheiros
                        </NavRow>
                        <NavRow href="/knowledge?view=review" active={filters.view === 'review'} count={pending} warn>
                            <Inbox className="size-4 text-muted-foreground" />
                            Para rever
                        </NavRow>
                    </div>
                    <div className="flex flex-col gap-0.5">
                        <h2 className="px-2.5 pb-1 text-xs font-medium tracking-widest text-muted-foreground uppercase">Domínios</h2>
                        {domains.map((d) => (
                            <NavRow key={d.id} href={`/knowledge?domain=${d.slug}`} active={domain?.id === d.id} count={d.items}>
                                <DomainDot color={d.color} />
                                <span className="truncate">{d.name}</span>
                                {d.restricted && <Lock className="size-3 shrink-0 text-muted-foreground" aria-label="Acesso restrito" />}
                            </NavRow>
                        ))}
                    </div>
                </nav>

                <div
                    className={cn(
                        'flex min-w-0 flex-col gap-5 rounded-xl transition-shadow',
                        dragging && 'ring-2 ring-primary/40 ring-offset-4 ring-offset-background',
                    )}
                    onDragOver={(e) => {
                        e.preventDefault();
                        setDragging(true);
                    }}
                    onDragLeave={() => setDragging(false)}
                    onDrop={onDrop}
                >
                    <form onSubmit={search} className="flex gap-2">
                        <div className="relative flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                className="pl-9"
                                placeholder={domain ? `Pesquisar em ${domain.name}…` : 'Pesquisar em toda a base de conhecimento…'}
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                            />
                        </div>
                        <Button type="submit" variant="outline">
                            Pesquisar
                        </Button>
                    </form>

                    {domain && browsing && filters.view !== 'files' && (
                        <div className="flex flex-col gap-1">
                            <div className="flex flex-wrap items-center gap-1 text-sm">
                                <Link
                                    href={`/knowledge?domain=${domain.slug}`}
                                    className={cn('hover:underline', folder ? 'text-muted-foreground' : 'font-medium')}
                                >
                                    {domain.name}
                                </Link>
                                {folder?.path.map((f, i) => (
                                    <span key={f.id} className="flex items-center gap-1">
                                        <ChevronRight className="size-3.5 text-muted-foreground" />
                                        <Link
                                            href={`/knowledge?domain=${domain.slug}&folder=${f.id}`}
                                            className={cn('hover:underline', i === folder.path.length - 1 ? 'font-medium' : 'text-muted-foreground')}
                                        >
                                            {f.name}
                                        </Link>
                                    </span>
                                ))}
                                {domain.restricted && (
                                    <StatusBadge
                                        tone="idle"
                                        dot={false}
                                        className="ml-2"
                                        title="Só alguns departamentos, proprietários e administradores vêem este domínio"
                                    >
                                        <Lock className="size-3" />
                                        restrito
                                    </StatusBadge>
                                )}
                            </div>
                            {!folder && domain.description && <p className="text-sm text-muted-foreground">{domain.description}</p>}
                        </div>
                    )}

                    {filing && (domain || filters.view === 'files') && (
                        <div className="flex flex-wrap items-center gap-2">
                            <div className="flex flex-wrap gap-1">
                                {KINDS.map((k) => (
                                    <Link
                                        key={k.label}
                                        href={kindHref(k.value)}
                                        preserveState
                                        className={cn(
                                            'rounded-full border px-2.5 py-1 text-xs transition-colors',
                                            filters.kind === k.value ? 'border-foreground/20 bg-accent font-medium text-foreground' : 'text-muted-foreground hover:bg-accent/60',
                                        )}
                                    >
                                        {k.label}
                                    </Link>
                                ))}
                            </div>
                            <div className="ml-auto flex rounded-lg border p-0.5" role="group" aria-label="Vista">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className={cn('size-7', layout === 'list' && 'bg-accent')}
                                    aria-label="Lista"
                                    aria-pressed={layout === 'list'}
                                    onClick={() => switchLayout('list')}
                                >
                                    <List className="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className={cn('size-7', layout === 'grid' && 'bg-accent')}
                                    aria-label="Grelha"
                                    aria-pressed={layout === 'grid'}
                                    onClick={() => switchLayout('grid')}
                                >
                                    <LayoutGrid className="size-4" />
                                </Button>
                            </div>
                        </div>
                    )}

                    {browsing && domain && filters.view !== 'files' && folders.length > 0 && (
                        <Section title="Pastas">
                            <ListPanel>
                                {folders.map((f) => (
                                    <EntityRow
                                        key={f.id}
                                        href={`/knowledge?domain=${domain.slug}&folder=${f.id}`}
                                        leading={
                                            <span className="inline-flex size-7 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                <Folder className="size-3.5" />
                                            </span>
                                        }
                                        title={f.name}
                                        meta={
                                            <span className="tabular-nums">
                                                {f.items} {f.items === 1 ? 'item' : 'itens'}
                                                {f.children > 0 && ` · ${f.children} ${f.children === 1 ? 'pasta' : 'pastas'}`}
                                            </span>
                                        }
                                        trailing={<ChevronRight className="size-4 text-muted-foreground" />}
                                    />
                                ))}
                            </ListPanel>
                        </Section>
                    )}

                    {items.length === 0 && browsing && domain && filters.view !== 'files' && folders.length > 0 ? null : items.length === 0 ? (
                        <EmptyState
                            icon={filters.view === 'review' ? Inbox : Library}
                            title={
                                filters.q
                                    ? 'Sem resultados'
                                    : filters.view === 'review'
                                      ? 'Nada para rever'
                                      : filters.kind
                                        ? 'Nenhum ficheiro deste tipo'
                                        : filters.view === 'files'
                                          ? 'Ainda não há ficheiros'
                                          : domain
                                            ? 'Pasta vazia'
                                            : 'Base de conhecimento vazia'
                            }
                            description={
                                filters.q
                                    ? 'Tente outras palavras ou uma pergunta mais curta.'
                                    : filters.view === 'review'
                                      ? 'Quando um agente com autonomia abaixo de N3 guardar algo, aparece aqui até alguém o aprovar.'
                                      : 'Arraste para aqui ficheiros ou pastas inteiras: PDF (também digitalizados), Word, Excel, PowerPoint e imagens. Os agentes passam a consultá-los.'
                            }
                            action={
                                !filters.q &&
                                filters.view !== 'review' && (
                                    <Button variant="outline" size="sm" onClick={() => fileInput.current?.click()} disabled={target === null}>
                                        <Upload />
                                        Carregar ficheiros
                                    </Button>
                                )
                            }
                        />
                    ) : (
                        <Section title={heading} action={<span className="text-xs text-muted-foreground tabular-nums">{items.length}</span>}>
                            {layout === 'grid' && filing ? (
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-4">
                                    {paged.items.map((item) => (
                                        <FileCard key={item.id} item={item} showDomain={!domain || filters.view === 'files'} />
                                    ))}
                                </div>
                            ) : (
                                <ListPanel>
                                    {paged.items.map((item) => (
                                        <KnowledgeEntry key={item.id} item={item} showDomain={!domain || !browsing || filters.view === 'files'} />
                                    ))}
                                </ListPanel>
                            )}
                            {items.length > (layout === 'grid' ? 24 : 20) && <PaginationBar {...paged.pager} noun={['item', 'itens']} />}
                        </Section>
                    )}
                </div>
            </div>

            <UploadDialog
                picked={picked}
                onClose={() => setPicked([])}
                domains={domains}
                domainId={target?.id ?? null}
                folder={domain && folder ? { id: folder.id, domain_id: domain.id, path: folder.path.map((f) => f.name).join(' / ') } : null}
                limits={upload}
            />

            <Dialog open={creatingFolder} onOpenChange={setCreatingFolder}>
                <DialogContent>
                    <form onSubmit={submitFolder} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>Nova pasta</DialogTitle>
                            <DialogDescription>
                                Em {domain?.name}
                                {folder ? ` / ${folder.path.map((f) => f.name).join(' / ')}` : ''}.
                            </DialogDescription>
                        </DialogHeader>
                        <Field id="folder-name" label="Nome" error={folderForm.errors.name}>
                            <Input
                                id="folder-name"
                                autoFocus
                                value={folderForm.data.name}
                                onChange={(e) => folderForm.setData('name', e.target.value)}
                            />
                        </Field>
                        <DialogFooter>
                            <Button type="button" variant="ghost" onClick={() => setCreatingFolder(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={folderForm.processing || folderForm.data.name.trim() === ''}>
                                Criar pasta
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

export function KnowledgeEntry({ item, showDomain }: { item: KnowledgeRow; showDomain: boolean }) {
    const review = reviewState(item.status);
    const index = indexState(item.embedding_status);

    return (
        <EntityRow
            href={`/knowledge/${item.id}${item.page ? `?page=${item.page}` : ''}`}
            className="py-3"
            leading={<FileIcon extension={item.file?.extension} />}
            title={item.title}
            subtitle={item.excerpt || (item.file ? item.file.name : '')}
            meta={
                <>
                    {item.page !== null && (
                        <span className="font-mono tabular-nums" title="Página do excerto">
                            p. {item.page}
                        </span>
                    )}
                    {item.file?.pages && !item.page ? (
                        <span className="tabular-nums">
                            {item.file.pages} {item.file.pages === 1 ? 'pág.' : 'págs.'}
                        </span>
                    ) : null}
                    {item.score !== null && item.score > 0 && (
                        <span className="font-mono tabular-nums" title="Relevância">
                            {Math.round(item.score * 100)}%
                        </span>
                    )}
                    {showDomain && item.domain && (
                        <span className="flex items-center gap-1.5">
                            <DomainDot color={item.domain.color} className="size-1.5" />
                            {item.domain.name}
                        </span>
                    )}
                    <span
                        className="flex max-w-36 items-center gap-1 truncate"
                        title={item.author.kind === 'agent' ? 'Escrito por um agente' : undefined}
                    >
                        {item.author.kind === 'agent' && <Bot className="size-3.5 shrink-0 text-primary" />}
                        <span className="truncate">{item.author.name}</span>
                    </span>
                    <span className="w-20 text-right" title={dateTime(item.updated_at)}>
                        {ago(item.updated_at)}
                    </span>
                </>
            }
            trailing={
                (review || index || item.file?.reading) && (
                    <>
                        {review && <StatusBadge tone={review.tone}>{review.label}</StatusBadge>}
                        {!review && <ReadingBadge item={item} />}
                        {!review && !item.file?.reading && index && <StatusBadge tone={index.tone}>{index.label}</StatusBadge>}
                    </>
                )
            }
        />
    );
}
