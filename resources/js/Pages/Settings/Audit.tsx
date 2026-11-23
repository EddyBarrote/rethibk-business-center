import { Head, Link, router } from '@inertiajs/react';
import { ChevronDown, Download, Lock, ScrollText, Search, X } from 'lucide-react';
import { Fragment, type ReactNode, useEffect, useRef, useState } from 'react';

import { EmptyState } from '@/Components/EmptyState';
import { PageHeader } from '@/Components/PageHeader';
import { Pagination } from '@/Components/Pagination';
import { StatusBadge, type Tone } from '@/Components/Status';
import { Button, buttonVariants } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/Components/ui/table';
import SettingsLayout from '@/Layouts/SettingsLayout';
import { ago, dateTime, number } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Option, Paginated } from '@/types';

/*
 * Definições › Auditoria (docs/DECISOES.md, "Auditoria pesquisável e
 * exportável"): the whole audit trail of the company, newest first, with
 * every filter in the address, so the CSV export carries exactly the list on
 * screen. Entries with pay and personal data show without their details to
 * whoever may not manage people.
 */

type Result = 'ok' | 'denied' | 'error';

interface Entry {
    id: number;
    created_at: string;
    actor: { type: 'user' | 'agent' | 'system' | 'platform_admin'; type_label: string; id: number | null; name: string };
    action: string;
    action_label: string;
    result: Result;
    result_label: string;
    subject: { type: string | null; type_label: string; id: number | string; name: string | null; url: string | null } | null;
    details: Record<string, unknown> | null;
    withheld: boolean;
    ip: string | null;
}

interface Filters {
    from: string | null;
    to: string | null;
    actor: string | null;
    action: string | null;
    result: Result | null;
    subject: string | null;
    q: string;
}

interface Options {
    actors: { label: string; options: Option[] }[];
    actions: { family: string; label: string; prefix: string; options: Option[] }[];
    results: Option[];
    subjects: Option[];
}

interface Props {
    entries: Paginated<Entry>;
    filters: Filters;
    options: Options;
    reads_confidential: boolean;
    timezone: string;
}

const resultTone: Record<Result, Tone> = { ok: 'success', denied: 'warning', error: 'danger' };

const head = 'h-9 px-4 text-xs font-medium text-muted-foreground';

/** The address of the list (or of its export) with these filters; what is off stays out of it. */
function params(filters: Filters): Record<string, string> {
    return Object.fromEntries(Object.entries(filters).filter(([, value]) => value !== null && value !== '')) as Record<string, string>;
}

/** "erp.*" and "erp." are the same family; the select knows it as "erp.". */
const actionValue = (action: string | null) => (action ? action.replace(/\*$/, '') : '');

function FilterField({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
    return (
        <label className={cn('flex min-w-0 flex-col gap-1 text-[11px] font-medium text-muted-foreground', className)}>
            {label}
            {children}
        </label>
    );
}

function Value({ value }: { value: unknown }) {
    if (value === null || value === undefined || value === '') {
        return <span className="text-muted-foreground">—</span>;
    }
    if (typeof value === 'object') {
        return (
            <pre className="max-h-64 overflow-auto rounded-md bg-muted/60 p-2 font-mono text-[11px] leading-relaxed break-words whitespace-pre-wrap">
                {JSON.stringify(value, null, 2)}
            </pre>
        );
    }

    return <span className="break-words whitespace-pre-wrap">{String(value)}</span>;
}

function Details({ entry, timezone }: { entry: Entry; timezone: string }) {
    const exact = new Intl.DateTimeFormat('pt-PT', { dateStyle: 'medium', timeStyle: 'medium', timeZone: timezone }).format(new Date(entry.created_at));

    return (
        <div className="flex flex-col gap-3 py-1 text-xs">
            <dl className="flex flex-wrap gap-x-6 gap-y-1 text-muted-foreground">
                <div className="flex gap-1.5">
                    <dt>Registado</dt>
                    <dd className="text-foreground tabular-nums">{exact}</dd>
                </div>
                <div className="flex gap-1.5">
                    <dt>Acção</dt>
                    <dd className="font-mono text-foreground">{entry.action}</dd>
                </div>
                {entry.ip && (
                    <div className="flex gap-1.5">
                        <dt>IP</dt>
                        <dd className="font-mono text-foreground">{entry.ip}</dd>
                    </div>
                )}
                <div className="flex gap-1.5">
                    <dt>N.º</dt>
                    <dd className="font-mono text-foreground">{entry.id}</dd>
                </div>
            </dl>
            {entry.withheld ? (
                <p className="flex items-start gap-2 rounded-md border border-dashed px-3 py-2 text-muted-foreground">
                    <Lock className="mt-0.5 size-3.5 shrink-0" />
                    Detalhes com dados de pessoal (salários, contratos, documentos de identificação ou processos disciplinares): só quem tem a
                    permissão «Gerir pessoas e papéis» os vê.
                </p>
            ) : entry.details && Object.keys(entry.details).length > 0 ? (
                <dl className="grid gap-x-4 gap-y-2 sm:grid-cols-[minmax(7rem,max-content)_minmax(0,1fr)]">
                    {Object.entries(entry.details).map(([key, value]) => (
                        <Fragment key={key}>
                            <dt className="font-mono text-muted-foreground">{key}</dt>
                            <dd className="min-w-0">
                                <Value value={value} />
                            </dd>
                        </Fragment>
                    ))}
                </dl>
            ) : (
                <p className="text-muted-foreground">Sem detalhes.</p>
            )}
        </div>
    );
}

export default function Audit({ entries, filters, options, reads_confidential, timezone }: Props) {
    const [open, setOpen] = useState<number[]>([]);
    const [query, setQuery] = useState(filters.q);
    const typed = useRef(false);

    const go = (changes: Partial<Filters>) =>
        router.get('/settings/audit', params({ ...filters, ...changes }), {
            preserveState: true,
            preserveScroll: true,
            replace: changes.q !== undefined,
        });

    useEffect(() => {
        if (!typed.current) {
            setQuery(filters.q);
        }
    }, [filters.q]);

    // Searches as the person types, a moment after they stop.
    useEffect(() => {
        if (!typed.current) {
            return;
        }
        const timer = window.setTimeout(() => {
            typed.current = false;
            if (query.trim() !== filters.q) {
                go({ q: query.trim() });
            }
        }, 350);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query]);

    const search = (value: string) => {
        typed.current = true;
        setQuery(value);
    };

    const narrowed = Boolean(filters.from || filters.to || filters.actor || filters.action || filters.result || filters.subject || filters.q);
    const exportQuery = new URLSearchParams(params(filters)).toString();
    const exportUrl = `/settings/audit/export${exportQuery ? `?${exportQuery}` : ''}`;
    const toggle = (id: number) => setOpen((current) => (current.includes(id) ? current.filter((other) => other !== id) : [...current, id]));
    const withheldHere = !reads_confidential && entries.data.some((entry) => entry.withheld);

    return (
        <SettingsLayout>
            <Head title="Auditoria" />

            <PageHeader
                title="Auditoria"
                description="Tudo o que pessoas, agentes e o sistema fizeram na empresa: quem, o quê, quando e com que resultado. Os registos nunca se alteram nem se apagam."
                actions={
                    <a
                        href={exportUrl}
                        className={cn(buttonVariants({ variant: 'outline' }), entries.total === 0 && 'pointer-events-none opacity-50')}
                        aria-disabled={entries.total === 0}
                        title={`Exporta as ${number(entries.total)} entradas desta lista, em CSV para o Excel.`}
                    >
                        <Download />
                        Exportar CSV
                    </a>
                }
            />

            <div className="flex flex-col gap-3">
                <div className="relative min-w-0">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        type="search"
                        value={query}
                        onChange={(event) => search(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Escape' && query !== '') search('');
                            if (event.key === 'Enter') {
                                typed.current = false;
                                go({ q: query.trim() });
                            }
                        }}
                        placeholder="Pesquisar na acção ou nos detalhes (um nome, um número, uma ferramenta…)"
                        aria-label="Pesquisar na acção ou nos detalhes"
                        className="h-9 pr-8 pl-8 [&::-webkit-search-cancel-button]:hidden"
                    />
                    {query !== '' && (
                        <button
                            type="button"
                            onClick={() => search('')}
                            className="absolute top-1/2 right-2 -translate-y-1/2 rounded text-muted-foreground hover:text-foreground"
                            aria-label="Limpar pesquisa"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>

                <div className="grid grid-cols-2 gap-2 md:grid-cols-3 2xl:grid-cols-6">
                    <FilterField label="De">
                        <Input
                            type="date"
                            className="h-8 text-xs"
                            value={filters.from ?? ''}
                            max={filters.to ?? undefined}
                            onChange={(event) => go({ from: event.target.value || null })}
                        />
                    </FilterField>
                    <FilterField label="Até">
                        <Input
                            type="date"
                            className="h-8 text-xs"
                            value={filters.to ?? ''}
                            min={filters.from ?? undefined}
                            onChange={(event) => go({ to: event.target.value || null })}
                        />
                    </FilterField>
                    <FilterField label="Quem">
                        <NativeSelect className="h-8 w-full" aria-label="Quem" value={filters.actor ?? ''} onChange={(event) => go({ actor: event.target.value || null })}>
                            <option value="">Todos</option>
                            {options.actors.map((group) => (
                                <optgroup key={group.label} label={group.label}>
                                    {group.options.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </NativeSelect>
                    </FilterField>
                    <FilterField label="Acção">
                        <NativeSelect
                            className="h-8 w-full"
                            aria-label="Acção"
                            value={actionValue(filters.action)}
                            onChange={(event) => go({ action: event.target.value || null })}
                        >
                            <option value="">Todas</option>
                            {options.actions.map((family) => (
                                <optgroup key={family.family} label={family.label}>
                                    <option value={family.prefix}>{`Todas: ${family.label}`}</option>
                                    {family.options.map((option) => (
                                        <option key={option.value} value={option.value}>
                                            {option.label}
                                        </option>
                                    ))}
                                </optgroup>
                            ))}
                        </NativeSelect>
                    </FilterField>
                    <FilterField label="Resultado">
                        <NativeSelect
                            className="h-8 w-full"
                            aria-label="Resultado"
                            value={filters.result ?? ''}
                            onChange={(event) => go({ result: (event.target.value || null) as Result | null })}
                        >
                            <option value="">Todos</option>
                            {options.results.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FilterField>
                    <FilterField label="Objecto">
                        <NativeSelect className="h-8 w-full" aria-label="Objecto" value={filters.subject ?? ''} onChange={(event) => go({ subject: event.target.value || null })}>
                            <option value="">Todos</option>
                            {options.subjects.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </NativeSelect>
                    </FilterField>
                </div>

                {narrowed && (
                    <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                        <span className="tabular-nums">
                            {number(entries.total)} {entries.total === 1 ? 'entrada' : 'entradas'} com estes filtros
                        </span>
                        <Button
                            variant="ghost"
                            size="xs"
                            onClick={() => {
                                typed.current = false;
                                setQuery('');
                                router.get('/settings/audit', {}, { preserveState: true, preserveScroll: true });
                            }}
                        >
                            <X />
                            Limpar filtros
                        </Button>
                    </div>
                )}
            </div>

            {entries.data.length === 0 ? (
                <EmptyState
                    icon={ScrollText}
                    title={narrowed ? 'Nenhuma entrada' : 'Ainda sem registos'}
                    description={
                        narrowed
                            ? 'Nenhuma entrada da auditoria com estes filtros. Alargue o período ou limpe os filtros.'
                            : 'Cada acção de pessoas e agentes fica aqui registada assim que acontece.'
                    }
                />
            ) : (
                <div className="flex flex-col gap-3">
                    <div className="overflow-hidden rounded-xl border bg-card">
                        <Table className="table-stack">
                            <TableHeader>
                                <TableRow className="bg-muted/40 hover:bg-muted/40">
                                    <TableHead className={head}>Quando</TableHead>
                                    <TableHead className={head}>Quem</TableHead>
                                    <TableHead className={head}>Acção</TableHead>
                                    <TableHead className={head}>Resultado</TableHead>
                                    <TableHead className={head}>Objecto</TableHead>
                                    <TableHead className={cn(head, 'w-10')}>
                                        <span className="sr-only">Detalhes</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {entries.data.map((entry) => {
                                    const expanded = open.includes(entry.id);

                                    return (
                                        <Fragment key={entry.id}>
                                            <TableRow className={cn(expanded && 'border-b-0 bg-muted/20')}>
                                                <TableCell className="px-4 py-2 text-xs whitespace-nowrap text-muted-foreground" title={dateTime(entry.created_at)}>
                                                    {ago(entry.created_at)}
                                                </TableCell>
                                                <TableCell data-label="Quem" className="px-4 py-2">
                                                    <span className="block truncate text-sm">{entry.actor.name}</span>
                                                    {entry.actor.type !== 'system' && (
                                                        <span className="block text-[11px] text-muted-foreground">{entry.actor.type_label}</span>
                                                    )}
                                                </TableCell>
                                                <TableCell data-label="Acção" className="px-4 py-2" title={entry.action}>
                                                    <span className="text-sm">{entry.action_label}</span>
                                                    {entry.withheld && (
                                                        <Lock className="ml-1.5 inline size-3 align-baseline text-muted-foreground" aria-label="Com dados de pessoal" />
                                                    )}
                                                </TableCell>
                                                <TableCell data-label="Resultado" className="px-4 py-2">
                                                    <StatusBadge tone={resultTone[entry.result]}>{entry.result_label}</StatusBadge>
                                                </TableCell>
                                                <TableCell data-label="Objecto" className="px-4 py-2 text-sm">
                                                    {entry.subject ? (
                                                        <span className="flex min-w-0 flex-col">
                                                            <span className="text-[11px] text-muted-foreground">{entry.subject.type_label}</span>
                                                            {entry.subject.url ? (
                                                                <Link href={entry.subject.url} className="truncate underline-offset-2 hover:underline">
                                                                    {entry.subject.name ?? `#${entry.subject.id}`}
                                                                </Link>
                                                            ) : (
                                                                <span className="truncate">{entry.subject.name ?? `#${entry.subject.id}`}</span>
                                                            )}
                                                        </span>
                                                    ) : (
                                                        <span className="text-muted-foreground">—</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="px-2 py-2 text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon-sm"
                                                        onClick={() => toggle(entry.id)}
                                                        aria-expanded={expanded}
                                                        aria-label={expanded ? 'Esconder os detalhes' : 'Ver os detalhes'}
                                                    >
                                                        <ChevronDown className={cn('transition-transform', expanded && 'rotate-180')} />
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                            {expanded && (
                                                <TableRow className="bg-muted/20 hover:bg-muted/20">
                                                    <TableCell colSpan={6} className="px-4 pt-0 pb-3 whitespace-normal">
                                                        <Details entry={entry} timezone={timezone} />
                                                    </TableCell>
                                                </TableRow>
                                            )}
                                        </Fragment>
                                    );
                                })}
                            </TableBody>
                        </Table>
                    </div>
                    {withheldHere && (
                        <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Lock className="size-3" />
                            As entradas com dados de pessoal mostram-se sem os detalhes: só quem tem «Gerir pessoas e papéis» os vê.
                        </p>
                    )}
                </div>
            )}

            <Pagination page={entries} noun={['entrada', 'entradas']} />
        </SettingsLayout>
    );
}
