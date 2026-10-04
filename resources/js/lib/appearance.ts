import { useSyncExternalStore } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const storageKey = 'appearance';
const listeners = new Set<() => void>();

const media = () => window.matchMedia('(prefers-color-scheme: dark)');

function read(): Appearance {
    try {
        const value = localStorage.getItem(storageKey);

        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        return 'system';
    }
}

function resolve(appearance: Appearance): 'light' | 'dark' {
    return appearance === 'dark' || (appearance === 'system' && media().matches) ? 'dark' : 'light';
}

function apply() {
    const dark = resolve(read()) === 'dark';
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
}

function subscribe(listener: () => void) {
    listeners.add(listener);
    const onSystemChange = () => {
        apply();
        listener();
    };
    media().addEventListener('change', onSystemChange);

    return () => {
        listeners.delete(listener);
        media().removeEventListener('change', onSystemChange);
    };
}

export function setAppearance(value: Appearance) {
    try {
        localStorage.setItem(storageKey, value);
    } catch {
        // Private mode: the choice lasts for this page only.
    }
    apply();
    listeners.forEach((listener) => listener());
}

/** Light, dark or follow the system; kept per browser. The first paint is handled in app.blade.php. */
export function useAppearance() {
    const appearance = useSyncExternalStore(subscribe, read, () => 'system' as Appearance);
    const resolved = useSyncExternalStore(
        subscribe,
        () => resolve(read()),
        () => 'light' as const,
    );

    return { appearance, resolved, setAppearance } as const;
}
