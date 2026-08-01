/**
 * Thin fetch wrapper for the php backend.
 *
 * Two things it exists to get right, both easy to forget per call site:
 *  - Content-Type: application/json, which is what makes the router merge the body into
 *    $_POST. Without it the body is ignored and every field reads as missing.
 *  - X-CSRF-Token on anything that changes state.
 */

export interface ApiError {
    message: string;
    status: number;
}

const root = document.getElementById('react-root');
const csrfToken = root?.dataset.csrfToken ?? '';
export const apiBase = root?.dataset.apiBase ?? '/api/items';

async function request<T>(url: string, init: RequestInit = {}): Promise<T> {
    const response = await fetch(url, {
        ...init,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-Token': csrfToken,
            ...(init.headers ?? {}),
        },
        // The session cookie carries both the login and the csrf token
        credentials: 'same-origin',
    });

    const body = await response.json().catch(() => null);

    if (!response.ok) {
        // The framework's error pages answer in json when the request was json, so the
        // message is readable rather than a wall of html
        const error: ApiError = {
            message: body?.error?.message ?? body?.message ?? `Request failed (${response.status})`,
            status: response.status,
        };
        throw error;
    }

    return body as T;
}

export function get<T>(url: string): Promise<T> {
    return request<T>(url);
}

export function post<T>(url: string, data: unknown): Promise<T> {
    return request<T>(url, { method: 'POST', body: JSON.stringify(data) });
}
