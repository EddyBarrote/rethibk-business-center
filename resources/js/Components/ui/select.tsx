import * as React from 'react';

import { type SearchOption, SearchList, textOf } from '@/Components/ui/search-list';

/*
 * The shadcn Select API (Select, SelectTrigger, SelectValue, SelectContent,
 * SelectItem, SelectGroup, SelectLabel, SelectSeparator), drawn as the list
 * with a search of the platform (docs/DECISOES.md, "Selects com pesquisa").
 * Select reads its items from the tree it is given; the trigger keeps its
 * id, size, class and label.
 */

interface ItemProps {
    value: string;
    disabled?: boolean;
    children?: React.ReactNode;
}

function SelectItem(_props: ItemProps & { className?: string }) {
    return null;
}

function SelectGroup({ children }: { children?: React.ReactNode; className?: string }) {
    return <>{children}</>;
}

function SelectLabel({ children }: { children?: React.ReactNode; className?: string }) {
    return <>{children}</>;
}

function SelectSeparator(_props: { className?: string }) {
    return null;
}

function SelectValue(_props: { placeholder?: React.ReactNode; className?: string; children?: React.ReactNode }) {
    return null;
}

function SelectContent({ children }: { children?: React.ReactNode; className?: string; position?: string; align?: string }) {
    return <>{children}</>;
}

function SelectTrigger({
    children,
}: {
    children?: React.ReactNode;
    id?: string;
    className?: string;
    size?: 'sm' | 'default';
    'aria-label'?: string;
}) {
    return <>{children}</>;
}

/** The items under a SelectContent, groups named by their SelectLabel. */
function itemsOf(children: React.ReactNode, group?: string): SearchOption[] {
    let label = group;

    return React.Children.toArray(children).flatMap((child) => {
        if (!React.isValidElement<{ children?: React.ReactNode } & Partial<ItemProps>>(child)) {
            return [];
        }
        if (child.type === SelectLabel) {
            label = textOf(child.props.children);
            return [];
        }
        if (child.type === SelectGroup || child.type === React.Fragment) {
            return itemsOf(child.props.children, label);
        }
        if (child.type === SelectItem) {
            return [
                {
                    value: String(child.props.value),
                    label: child.props.children,
                    text: textOf(child.props.children).replace(/\s+/g, ' ').trim(),
                    disabled: child.props.disabled,
                    group: label,
                },
            ];
        }

        return [];
    });
}

function find<T>(children: React.ReactNode, type: React.ElementType): React.ReactElement<T> | undefined {
    for (const child of React.Children.toArray(children)) {
        if (React.isValidElement<T & { children?: React.ReactNode }>(child)) {
            if (child.type === type) {
                return child;
            }
            const inner = find<T>(child.props.children, type);
            if (inner) {
                return inner;
            }
        }
    }

    return undefined;
}

function Select({
    value,
    defaultValue,
    onValueChange,
    disabled,
    children,
}: {
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    disabled?: boolean;
    children?: React.ReactNode;
    name?: string;
    required?: boolean;
}) {
    const [inner, setInner] = React.useState(defaultValue ?? '');
    const trigger = find<{ id?: string; className?: string; size?: 'sm' | 'default'; 'aria-label'?: string; children?: React.ReactNode }>(
        children,
        SelectTrigger,
    );
    const content = find<{ children?: React.ReactNode }>(children, SelectContent);
    const shown = trigger
        ? find<{ placeholder?: React.ReactNode; children?: React.ReactNode }>(trigger.props.children, SelectValue)?.props
        : undefined;

    return (
        <SearchList
            options={itemsOf(content?.props.children)}
            value={value ?? inner}
            onChange={(next) => {
                setInner(next);
                onValueChange?.(next);
            }}
            placeholder={shown?.placeholder}
            display={shown?.children}
            disabled={disabled}
            id={trigger?.props.id}
            aria-label={trigger?.props['aria-label']}
            size={trigger?.props.size}
            className={trigger?.props.className ?? 'w-fit'}
        />
    );
}

export { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectSeparator, SelectTrigger, SelectValue };
