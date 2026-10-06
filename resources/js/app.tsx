import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

// Single Echo connection for the whole app; components subscribe through a
// shared hook (section 11.3 of docs/SPEC.md), never open their own. The values
// are passed explicitly: echo-react's own defaults are read inside the
// pre-bundled dependency, where Vite can keep a stale copy of the env.
const reverbKey = String(import.meta.env.VITE_REVERB_APP_KEY ?? '');

// An unexpanded "${REVERB_APP_KEY}" is not a key: screens then poll instead (see vite.config.ts).
if (reverbKey !== '' && !reverbKey.includes('${')) {
    configureEcho({
        broadcaster: 'reverb',
        key: reverbKey,
        wsHost: import.meta.env.VITE_REVERB_HOST,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}

const appName = import.meta.env.VITE_APP_NAME || 'Rethink Business Center';

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => {
        const pages = import.meta.glob<{ default: ComponentType }>('./Pages/**/*.tsx');
        const page = pages[`./Pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Página Inertia não encontrada: ${name}`);
        }

        return page();
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {
        color: 'var(--primary)',
    },
});
