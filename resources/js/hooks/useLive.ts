import { router } from '@inertiajs/react';
import { echo, echoIsConfigured } from '@laravel/echo-react';
import { useEffect, useRef } from 'react';

/**
 * Listen to events on a private tenant channel through the app's single Echo
 * connection (section 11.3). Without Reverb configured, falls back to reloading
 * the given props every few seconds while `poll` is true, so screens stay live
 * in local development too.
 */
export function useLive<T = Record<string, unknown>>(
    channel: string | null,
    events: string[],
    onEvent: (event: string, payload: T) => void,
    fallback?: { only: string[]; poll: boolean; intervalMs?: number },
) {
    const handler = useRef(onEvent);
    handler.current = onEvent;
    const eventsKey = events.join(',');

    useEffect(() => {
        if (!channel || !echoIsConfigured()) {
            return;
        }

        const subscription = echo().private(channel);
        const names = eventsKey.split(',');
        names.forEach((name) => subscription.listen(name, (payload: T) => handler.current(name, payload)));

        return () => names.forEach((name) => subscription.stopListening(name));
    }, [channel, eventsKey]);

    const only = fallback?.only.join(',') ?? '';
    const poll = fallback?.poll ?? false;
    const interval = fallback?.intervalMs ?? 3000;

    useEffect(() => {
        if (!poll || echoIsConfigured()) {
            return;
        }

        const timer = window.setInterval(() => router.reload({ only: only.split(',') }), interval);

        return () => window.clearInterval(timer);
    }, [poll, only, interval]);
}
