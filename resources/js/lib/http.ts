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
