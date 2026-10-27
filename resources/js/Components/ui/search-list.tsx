import { CheckIcon, ChevronDownIcon } from 'lucide-react';
import * as React from 'react';

import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/Components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/Components/ui/popover';
import { cn } from '@/lib/utils';

/*
 * Every choice from a list has a search (docs/DECISOES.md, "Selects com
 * pesquisa"): NativeSelect and Select both open this list, with the field to
 * search on top. The trigger looks like the field it replaces.
 */

export interface SearchOption {
    value: string;
    /** What the list and the trigger show. */
    label: React.ReactNode;
    /** What the search matches. */
    text: string;
    disabled?: boolean;
    group?: string;
}

/** The words inside a label, however it is built (a string, an icon and a name…). */
export function textOf(node: React.ReactNode): string {
    if (node === null || node === undefined || typeof node === 'boolean') {
        return '';
    }
    if (typeof node === 'string' || typeof node === 'number') {
        return String(node);
    }
    if (Array.isArray(node)) {
        return node.map(textOf).join(' ');
    }
    if (React.isValidElement<{ children?: React.ReactNode; label?: React.ReactNode }>(node)) {
        return [textOf(node.props.label), textOf(node.props.children)].filter(Boolean).join(' ');
    }

    return '';
}

export function SearchList({
    options,
    value,
    onChange,
    placeholder,
    disabled,
    id,
    className,
    triggerClassName,
    'aria-label': ariaLabel,
    size = 'default',
    display,
}: {
    options: SearchOption[];
    value: string;
    onChange: (value: string) => void;
    placeholder?: React.ReactNode;
    disabled?: boolean;
    id?: string;
    className?: string;
    triggerClassName?: string;
    'aria-label'?: string;
    size?: 'sm' | 'default';
    /** Shown in the trigger instead of the chosen option's label. */
    display?: React.ReactNode;
}) {
    const [open, setOpen] = React.useState(false);
    const selected = options.find((option) => option.value === value);
    const groups = options.reduce<{ name: string | undefined; items: SearchOption[] }[]>((list, option) => {
        const last = list[list.length - 1];
        if (last && last.name === option.group) {
            last.items.push(option);
        } else {
            list.push({ name: option.group, items: [option] });
        }

        return list;
    }, []);

    return (
        <Popover open={open} onOpenChange={setOpen} modal>
            <PopoverTrigger asChild disabled={disabled}>
                <button
                    type="button"
                    id={id}
                    role="combobox"
                    aria-expanded={open}
                    aria-label={ariaLabel}
                    data-slot="search-list"
                    data-size={size}
                    disabled={disabled}
                    className={cn(
                        'flex w-full min-w-0 items-center justify-between gap-2 rounded-md border border-input bg-transparent pr-2 pl-3 text-left text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive md:text-sm dark:bg-input/30 [&_svg:not([class*=size-])]:size-4 [&_svg:not([class*=text-])]:text-muted-foreground',
                        size === 'sm' ? 'h-8' : 'h-9',
                        className,
                        triggerClassName,
                    )}
                >
                    <span
                        className={cn(
                            'flex min-w-0 flex-1 items-center gap-2 truncate',
                            !selected && display === undefined && 'text-muted-foreground',
                        )}
                    >
                        {display ?? (selected ? selected.label : placeholder)}
                    </span>
                    <ChevronDownIcon className="size-4 shrink-0 opacity-50" />
                </button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-(--radix-popover-trigger-width) min-w-56 p-0">
                <Command
                    filter={(itemValue, search) => {
                        const option = options.find((o) => o.value === itemValue);
                        const haystack = `${option?.text ?? ''} ${option?.group ?? ''}`
                            .normalize('NFD')
                            .replace(/\p{Diacritic}/gu, '')
                            .toLowerCase();
                        const needle = search
                            .normalize('NFD')
                            .replace(/\p{Diacritic}/gu, '')
                            .toLowerCase()
                            .trim();

                        return needle === '' || needle.split(/\s+/).every((word) => haystack.includes(word)) ? 1 : 0;
                    }}
                >
                    <CommandInput placeholder="Procurar…" />
                    <CommandList className="max-h-72">
                        <CommandEmpty>Nada encontrado.</CommandEmpty>
                        {groups.map((group, index) => (
                            <CommandGroup key={`${group.name ?? ''}-${index}`} heading={group.name}>
                                {group.items.map((option) => (
                                    <CommandItem
                                        key={option.value}
                                        value={option.value}
                                        disabled={option.disabled}
                                        onSelect={() => {
                                            onChange(option.value);
                                            setOpen(false);
                                        }}
                                        className="gap-2"
                                    >
                                        <span className="flex min-w-0 flex-1 items-center gap-2">{option.label}</span>
                                        <CheckIcon className={cn('size-4 shrink-0', option.value === value ? 'opacity-100' : 'opacity-0')} />
                                    </CommandItem>
                                ))}
                            </CommandGroup>
                        ))}
                    </CommandList>
                </Command>
            </PopoverContent>
        </Popover>
    );
}
