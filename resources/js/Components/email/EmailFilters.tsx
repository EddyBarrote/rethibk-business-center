import { router } from '@inertiajs/react';
import { Search, SlidersHorizontal, X } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';

import { Button } from '@/Components/ui/button';
import { Input } from '@/Components/ui/input';
import { NativeSelect } from '@/Components/ui/native-select';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { Switch } from '@/Components/ui/switch';
import { cn } from '@/lib/utils';
import type { Option } from '@/types';

/** What the list is filtered by, as it travels in the address. */
export interface EmailListFilters {
    mailbox: number | 'routed' | null;
    folder: number | null;
    q: string;
    category: string | null;
    em: 'todas' | null;
    leitura: 'nao_lidos' | 'lidos' | null;
    anexos: boolean;
    estrela: boolean;
    periodo: 'hoje' | '7d' | '30d' | '90d' | null;
    de: string | null;
    ate: string | null;
    triagem: string | null;
}

type Changes = Partial<Omit<EmailListFilters, 'mailbox' | 'folder'>>;

const periods: Record<NonNullable<EmailListFilters['periodo']>, string> = {
    hoje: 'Hoje',
    '7d': 'Últimos 7 dias',
    '30d': 'Últimos 30 dias',
    '90d': 'Últimos 90 dias',
};

const shortDate = (value: string) => new Date(`${value}T00:00:00`).toLocaleDateString('pt-PT', { day: 'numeric', month: 'short' });

/** The address of the list with these filters; what is off stays out of it. */
export function filterParams(filters: EmailListFilters): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (filters.mailbox !== null) params.mailbox = filters.mailbox;
    if (filters.folder !== null) params.folder = filters.folder;
    if (filters.q) params.q = filters.q;
    if (filters.category) params.category = filters.category;
    if (filters.em) params.em = filters.em;
    if (filters.leitura) params.leitura = filters.leitura;
    if (filters.anexos) params.anexos = 1;
    if (filters.estrela) params.estrela = 1;
    if (filters.periodo) params.periodo = filters.periodo;
    if (!filters.periodo && filters.de) params.de = filters.de;
    if (!filters.periodo && filters.ate) params.ate = filters.ate;
    if (filters.triagem) params.triagem = filters.triagem;

    return params;
}

/** Whether any filter (search aside) narrows the list. */
export const narrowed = (f: EmailListFilters) => Boolean(f.category || f.leitura || f.anexos || f.estrela || f.periodo || f.de || f.ate || f.triagem);

function Segmented<T extends string>({
    value,
    options,
    onChange,
    label,
}: {
    value: T;
    options: { value: T; label: string }[];
    onChange: (value: T) => void;
    label: string;
}) {
    return (
        <div role="radiogroup" aria-label={label} className="grid auto-cols-fr grid-flow-col gap-0.5 rounded-lg bg-muted p-0.5">
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    role="radio"
                    aria-checked={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'h-7 truncate rounded-md px-2 text-xs transition-colors',
                        value === option.value
                            ? 'bg-background font-medium text-foreground shadow-xs'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div className="flex flex-col gap-1.5">
            <p className="text-[11px] font-medium text-muted-foreground">{title}</p>
            {children}
        </div>
    );
}

/**
 * Search and filters of the email list, like the other lists of the
 * platform: the search goes as the person types, every filter is in the
 * address, and each one on shows as a chip that takes it off.
 */
export function EmailFilters({
    filters,
    folderName,
    placeholder,
    categories,
    triageStates,
}: {
    filters: EmailListFilters;
    folderName: string | null;
    placeholder: string;
    categories: Option[];
    triageStates: Option[];
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState(filters.q);
    const typed = useRef(false);
    const triage = triageStates.length > 0;
    const canWiden = typeof filters.mailbox === 'number';

    const go = (changes: Changes) =>
        router.get('/inbox', filterParams({ ...filters, ...changes }), {
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

    const categoryLabel = categories.find((c) => c.value === filters.category)?.label ?? filters.category;
    const dateLabel = filters.periodo
        ? periods[filters.periodo]
        : filters.de && filters.ate
          ? `${shortDate(filters.de)} – ${shortDate(filters.ate)}`
          : filters.de
            ? `Desde ${shortDate(filters.de)}`
            : filters.ate
              ? `Até ${shortDate(filters.ate)}`
              : null;

    const chips = [
        filters.em && { key: 'em', label: 'Todas as pastas', clear: { em: null } },
        filters.leitura && { key: 'leitura', label: filters.leitura === 'nao_lidos' ? 'Não lidos' : 'Lidos', clear: { leitura: null } },
        filters.anexos && { key: 'anexos', label: 'Com anexos', clear: { anexos: false } },
        filters.estrela && { key: 'estrela', label: 'Com estrela', clear: { estrela: false } },
        dateLabel && { key: 'data', label: dateLabel, clear: { periodo: null, de: null, ate: null } },
        filters.triagem && {
            key: 'triagem',
            label: `Triagem: ${triageStates.find((s) => s.value === filters.triagem)?.label ?? filters.triagem}`,
            clear: { triagem: null },
        },
        filters.category && { key: 'category', label: categoryLabel ?? '', clear: { category: null } },
    ].filter(Boolean) as { key: string; label: string; clear: Changes }[];
    const active = chips.filter((chip) => chip.key !== 'em').length;
    const dateMode = filters.periodo ?? (filters.de || filters.ate ? 'datas' : 'qualquer');

    const clearAll = () => {
        typed.current = false;
        setQuery('');
        go({ q: '', em: null, leitura: null, anexos: false, estrela: false, periodo: null, de: null, ate: null, triagem: null, category: null });
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center gap-1.5">
                <div className="relative min-w-0 flex-1">
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
                        placeholder={placeholder}
                        aria-label="Pesquisar emails por assunto, remetente ou texto"
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
                <Popover open={open} onOpenChange={setOpen}>
                    <PopoverTrigger asChild>
                        <Button
                            variant="outline"
                            size="sm"
                            className={cn('h-9 shrink-0 gap-1.5 px-2.5', active > 0 && 'border-primary/40 bg-primary/5')}
                            aria-label={active > 0 ? `Filtros (${active} activos)` : 'Filtros'}
                        >
                            <SlidersHorizontal />
                            <span className="hidden sm:inline">Filtros</span>
                            {active > 0 && (
                                <span className="grid size-4 place-items-center rounded-full bg-primary text-[10px] font-medium text-primary-foreground tabular-nums">
                                    {active}
                                </span>
                            )}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent align="end" className="flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-4 p-3">
                        {canWiden && (
                            <Section title="Procurar em">
                                <Segmented
                                    label="Procurar em"
                                    value={filters.em ?? 'pasta'}
                                    onChange={(em) => go({ em: em === 'todas' ? 'todas' : null })}
                                    options={[
                                        { value: 'pasta', label: folderName ?? 'Esta pasta' },
                                        { value: 'todas', label: 'Todas as pastas' },
                                    ]}
                                />
                            </Section>
                        )}
                        <Section title="Leitura">
                            <Segmented
                                label="Leitura"
                                value={filters.leitura ?? 'todos'}
                                onChange={(leitura) => go({ leitura: leitura === 'todos' ? null : leitura })}
                                options={[
                                    { value: 'todos', label: 'Todos' },
                                    { value: 'nao_lidos', label: 'Não lidos' },
                                    { value: 'lidos', label: 'Lidos' },
                                ]}
                            />
                        </Section>
                        <div className="flex flex-col gap-2">
                            <label className="flex items-center justify-between gap-3 text-sm">
                                Com anexos
                                <Switch checked={filters.anexos} onCheckedChange={(anexos) => go({ anexos })} />
                            </label>
                            <label className="flex items-center justify-between gap-3 text-sm">
                                Com estrela
                                <Switch checked={filters.estrela} onCheckedChange={(estrela) => go({ estrela })} />
                            </label>
                        </div>
                        <Section title="Data">
                            <NativeSelect
                                aria-label="Data"
                                className="h-8 w-full"
                                value={dateMode}
                                onChange={(event) => {
                                    const value = event.target.value;
                                    if (value === 'qualquer') go({ periodo: null, de: null, ate: null });
                                    else if (value === 'datas')
                                        go({ periodo: null, de: new Date(Date.now() - 30 * 86_400_000).toISOString().slice(0, 10), ate: null });
                                    else go({ periodo: value as EmailListFilters['periodo'], de: null, ate: null });
                                }}
                            >
                                <option value="qualquer">Qualquer data</option>
                                {Object.entries(periods).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                                <option value="datas">Entre datas…</option>
                            </NativeSelect>
                            {dateMode === 'datas' && (
                                <div className="grid grid-cols-2 gap-2">
                                    <label className="flex flex-col gap-1 text-[11px] text-muted-foreground">
                                        De
                                        <Input
                                            type="date"
                                            className="h-8 text-xs"
                                            value={filters.de ?? ''}
                                            max={filters.ate ?? undefined}
                                            onChange={(event) => go({ de: event.target.value || null })}
                                        />
                                    </label>
                                    <label className="flex flex-col gap-1 text-[11px] text-muted-foreground">
                                        Até
                                        <Input
                                            type="date"
                                            className="h-8 text-xs"
                                            value={filters.ate ?? ''}
                                            min={filters.de ?? undefined}
                                            onChange={(event) => go({ ate: event.target.value || null })}
                                        />
                                    </label>
                                </div>
                            )}
                        </Section>
                        {triage && (
                            <Section title="Triagem">
                                <div className="grid gap-2">
                                    <NativeSelect
                                        aria-label="Estado da triagem"
                                        className="h-8 w-full"
                                        value={filters.triagem ?? ''}
                                        onChange={(event) => go({ triagem: event.target.value || null })}
                                    >
                                        <option value="">Qualquer estado</option>
                                        {triageStates.map((state) => (
                                            <option key={state.value} value={state.value}>
                                                {state.label}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                    <NativeSelect
                                        aria-label="Categoria"
                                        className="h-8 w-full"
                                        value={filters.category ?? ''}
                                        onChange={(event) => go({ category: event.target.value || null })}
                                    >
                                        <option value="">Qualquer categoria</option>
                                        {categories.map((category) => (
                                            <option key={category.value} value={category.value}>
                                                {category.label}
                                            </option>
                                        ))}
                                        <option value="none">Sem categoria</option>
                                    </NativeSelect>
                                </div>
                            </Section>
                        )}
                        {(active > 0 || filters.em) && (
                            <Button variant="ghost" size="sm" className="h-7 self-start text-xs" onClick={clearAll}>
                                Limpar filtros
                            </Button>
                        )}
                    </PopoverContent>
                </Popover>
            </div>

            {chips.length > 0 && (
                <div className="flex flex-wrap items-center gap-1">
                    {chips.map((chip) => (
                        <button
                            key={chip.key}
                            type="button"
                            onClick={() => go(chip.clear)}
                            className="inline-flex h-6 max-w-full items-center gap-1 rounded-full border bg-card px-2 text-[11px] text-foreground/80 hover:bg-accent"
                            title="Retirar filtro"
                        >
                            <span className="truncate">{chip.label}</span>
                            <X className="size-3 shrink-0 text-muted-foreground" />
                        </button>
                    ))}
                    <button
                        type="button"
                        onClick={clearAll}
                        className="h-6 rounded-md px-1.5 text-[11px] text-muted-foreground hover:text-foreground"
                    >
                        Limpar
                    </button>
                </div>
            )}
        </div>
    );
}
