import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { fileURLToPath } from 'node:url';
import { defineConfig, loadEnv } from 'vite';

/**
 * `php artisan reverb:install` appends REVERB_APP_KEY at the end of .env, after
 * the VITE_REVERB_* lines that reference it, so Vite's expansion leaves the
 * literal "${REVERB_APP_KEY}" and the browser connects to Reverb with a wrong
 * key: nothing arrives live. Resolve those references here, in any order.
 * Only the public VITE_REVERB_* values reach the browser, never the secret.
 */
function resolveReverbEnv(mode: string) {
    const env = loadEnv(mode, process.cwd(), '');

    for (const name of ['APP_KEY', 'HOST', 'PORT', 'SCHEME']) {
        const value = env[`VITE_REVERB_${name}`];

        if (value === undefined || value.includes('${')) {
            const resolved = env[`REVERB_${name}`];

            if (resolved) {
                process.env[`VITE_REVERB_${name}`] = resolved;
            }
        }
    }
}

export default defineConfig(({ mode }) => {
    resolveReverbEnv(mode);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
            }),
            react(),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            },
        },
        server: {
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
