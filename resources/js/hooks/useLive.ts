import { router } from '@inertiajs/react';
import { echo, echoIsConfigured } from '@laravel/echo-react';
import { useEffect, useRef, useState } from 'react';

/**
 * Listen to events on a private tenant channel through the app's single Echo
 * connection (section 11.3). Whenever the socket is not actually connected
 * (Reverb not configured, down, or refusing the key), falls back to reloading
 * the given props every few seconds while `poll` is true, so screens stay live.
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
    const connected = useSocketConnected();

    useEffect(() => {
        if (!poll || connected) {
            return;
        }

        const timer = window.setInterval(() => router.reload({ only: only.split(',') }), interval);

        return () => window.clearInterval(timer);
    }, [poll, only, interval]);
}

type PusherConnection = { state: string; bind: (event: string, cb: () => void) => void; unbind: (event: string, cb: () => void) => void };

function socketConnection(): PusherConnection | null {
    if (!echoIsConfigured()) {
        return null;
    }

    const connector = echo().connector as unknown as { pusher?: { connection?: PusherConnection } };

    return connector.pusher?.connection ?? null;
}

/** Whether the shared socket is connected right now; re-renders when that changes. */
function useSocketConnected(): boolean {
    const [connected, setConnected] = useState(() => socketConnection()?.state === 'connected');

    useEffect(() => {
        const connection = socketConnection();

        if (connection === null) {
            setConnected(false);

            return;
        }

        const update = () => setConnected(connection.state === 'connected');
        update();
        connection.bind('state_change', update);

        return () => connection.unbind('state_change', update);
    }, []);

    return connected;
}
