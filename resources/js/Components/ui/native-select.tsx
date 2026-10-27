import * as React from 'react';

import { type SearchOption, SearchList, textOf } from '@/Components/ui/search-list';

/**
 * The platform's select, written like a native <select> (value, onChange,
 * <option>, <optgroup>), opened as a list with a search (docs/DECISOES.md,
 * "Selects com pesquisa"). onChange gets an event-like object, so callers
 * read event.target.value as before. With a name, a hidden <select> carries
 * the value in a plain form post.
 */
type Props = Omit<React.ComponentProps<'select'>, 'onChange' | 'value'> & {
    value?: string | number | readonly string[];
    onChange?: (event: { target: { value: string; name?: string }; currentTarget: { value: string } }) => void;
};

function optionsOf(children: React.ReactNode, group?: string): SearchOption[] {
    return React.Children.toArray(children).flatMap((child) => {
        if (!React.isValidElement<{ value?: string | number; disabled?: boolean; label?: string; children?: React.ReactNode }>(child)) {
            return [];
        }
        if (child.type === 'optgroup') {
            return optionsOf(child.props.children, child.props.label);
        }
        if (child.type === React.Fragment) {
            return optionsOf(child.props.children, group);
        }
        if (child.type !== 'option') {
            return [];
        }
        const label = child.props.children;
        const text = textOf(label).replace(/\s+/g, ' ').trim();

        return [{ value: String(child.props.value ?? text), label: text || label, text, disabled: child.props.disabled, group }];
    });
}

function NativeSelect({ className, children, value, defaultValue, onChange, disabled, id, name, 'aria-label': ariaLabel }: Props) {
    const options = optionsOf(children);
    const [inner, setInner] = React.useState(String(defaultValue ?? options[0]?.value ?? ''));
    const current = value !== undefined ? String(value) : inner;
    // Like a native select, an unknown value shows the first option.
    const shown = options.some((option) => option.value === current) ? current : (options[0]?.value ?? '');

    return (
        <>
            <SearchList
                options={options}
                value={shown}
                onChange={(next) => {
                    setInner(next);
                    onChange?.({ target: { value: next, name }, currentTarget: { value: next } });
                }}
                disabled={disabled}
                id={id}
                aria-label={ariaLabel}
                className={className}
            />
            {name && <input type="hidden" name={name} value={current} />}
        </>
    );
}

export { NativeSelect };
