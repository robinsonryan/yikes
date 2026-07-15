import { bootstrap } from "./bootstrap";

/**
 * Minimal fetch-based HTTP client for the island — JSON in/out, CSRF from
 * the XSRF-TOKEN cookie (fresh) falling back to the bootstrap token, and
 * Laravel 422 validation errors surfaced as a typed error.
 */

export class YikesRequestError extends Error {
    constructor(
        public readonly status: number,
        message: string,
        /** Field → first message, flattened from Laravel's errors bag. */
        public readonly errors: Record<string, string> = {},
    ) {
        super(message);
    }
}

export function noteUrl(path = ""): string {
    return bootstrap().base + path;
}

export function testingUrl(...segments: string[]): string {
    const suffix = segments.map((segment) => encodeURIComponent(segment)).join("/");

    return bootstrap().testingBase + (suffix ? `/${suffix}` : "");
}

function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : bootstrap().csrf;
}

function csrfHeader(): Record<string, string> {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match
        ? { "X-XSRF-TOKEN": decodeURIComponent(match[1]) }
        : { "X-CSRF-TOKEN": csrfToken() };
}

async function request<T>(method: string, url: string, body?: BodyInit, json = true): Promise<T> {
    const headers: Record<string, string> = {
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        ...(method === "GET" ? {} : csrfHeader()),
        ...(json && body !== undefined ? { "Content-Type": "application/json" } : {}),
    };

    const response = await fetch(url, { method, headers, body, credentials: "same-origin" });

    const text = await response.text();
    let payload: unknown = null;
    try {
        payload = text === "" ? null : JSON.parse(text);
    } catch {
        // Non-JSON error page — fall through to the generic error below.
    }

    if (!response.ok) {
        const data = (payload ?? {}) as { message?: string; errors?: Record<string, string[]> };
        const errors: Record<string, string> = {};
        for (const [field, messages] of Object.entries(data.errors ?? {})) {
            if (messages.length > 0) {
                errors[field] = messages[0];
            }
        }
        throw new YikesRequestError(response.status, data.message ?? `Request failed (${response.status})`, errors);
    }

    return payload as T;
}

export const api = {
    get: <T>(url: string) => request<T>("GET", url),
    post: <T>(url: string, data?: unknown) => request<T>("POST", url, data === undefined ? undefined : JSON.stringify(data)),
    patch: <T>(url: string, data?: unknown) => request<T>("PATCH", url, data === undefined ? undefined : JSON.stringify(data)),
    delete: <T>(url: string) => request<T>("DELETE", url),
    /** multipart upload (screenshots) — no JSON content type. */
    postForm: <T>(url: string, form: FormData) => request<T>("POST", url, form, false),
};
