/**
 * A JSON request to the app's own server, with Laravel's CSRF token from the
 * XSRF-TOKEN cookie. For the few calls that are not page visits (the flow
 * editor's live check and "Propor passos").
 */
export async function postJson<T>(url: string, body: unknown): Promise<T> {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: JSON.stringify(body),
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error((data as { message?: string }).message ?? 'O pedido falhou.');
    }

    return data as T;
}

/**
 * A POST whose answer arrives as lines of JSON, one at a time, while the
 * server works («Criar com IA» shows each as it comes).
 */
export async function postStream<T>(url: string, body: unknown, onLine: (line: T) => void): Promise<void> {
    const token = document.cookie
        .split('; ')
        .find((part) => part.startsWith('XSRF-TOKEN='))
        ?.slice('XSRF-TOKEN='.length);

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/x-ndjson, application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: JSON.stringify(body),
    });

    if (!response.ok || !response.body) {
        const data = await response.json().catch(() => ({}));

        throw new Error((data as { message?: string }).message ?? 'O pedido falhou.');
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    for (;;) {
        const { done, value } = await reader.read();
        buffer += decoder.decode(value, { stream: !done });
        const lines = buffer.split('\n');
        buffer = done ? '' : (lines.pop() ?? '');
        lines.filter((line) => line.trim() !== '').forEach((line) => onLine(JSON.parse(line) as T));

        if (done) {
            return;
        }
    }
}
