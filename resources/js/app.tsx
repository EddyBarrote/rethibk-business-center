import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { configureEcho } from '@laravel/echo-react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

// Single Echo connection for the whole app; components subscribe through a
// shared hook (section 11.3 of docs/SPEC.md), never open their own.
// An unexpanded "${REVERB_APP_KEY}" is not a key: screens then poll instead (see vite.config.ts).
if (import.meta.env.VITE_REVERB_APP_KEY && !String(import.meta.env.VITE_REVERB_APP_KEY).includes('${')) {
    configureEcho({ broadcaster: 'reverb' });
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
