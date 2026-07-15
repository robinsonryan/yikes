import { bootstrap } from "./bootstrap";
import type { YikesContext } from "./types";

/**
 * Auto-captured page context, universal edition.
 *
 * The island captures what any page can provide (URL, route name, title,
 * dark mode, viewport, user agent). Everything richer — an SPA's page
 * component name, the active account/department, a state snapshot — comes
 * from host-registered providers:
 *
 *     window.Yikes.registerContextProvider(() => ({ page: ..., account: ... }));
 *     window.Yikes.registerStateProvider(() => piniaSnapshotObject);
 *
 * Host scripts usually run before the island (which loads at the end of
 * <body>), so a pre-island queue is honored too:
 *
 *     (window.YikesReady ||= []).push((yikes) => { yikes.registerContextProvider(...); });
 */

export type ContextProvider = () => Partial<YikesContext> | null | undefined;
export type StateProvider = () => unknown;

export interface YikesGlobalApi {
    registerContextProvider(provider: ContextProvider): void;
    registerStateProvider(provider: StateProvider): void;
}

declare global {
    interface Window {
        Yikes?: YikesGlobalApi;
        YikesReady?: ((yikes: YikesGlobalApi) => void)[];
    }
}

/**
 * Frontend mirror of the `yikes.max_state_kb` config default (256KB) — keep
 * in sync with src/Config/yikes.php.
 */
export const MAX_STATE_BYTES = 256 * 1024;

export const STATE_TRUNCATION_MARKER = "…[yikes: state snapshot truncated at 256KB]";

const contextProviders: ContextProvider[] = [];
const stateProviders: StateProvider[] = [];

export function installGlobalApi(): void {
    // A fresh install starts a fresh registry (also isolates tests).
    contextProviders.length = 0;
    stateProviders.length = 0;

    const yikes: YikesGlobalApi = {
        registerContextProvider(provider) {
            contextProviders.push(provider);
        },
        registerStateProvider(provider) {
            stateProviders.push(provider);
        },
    };

    window.Yikes = yikes;

    const queued = window.YikesReady;
    if (Array.isArray(queued)) {
        for (const callback of queued) {
            try {
                callback(yikes);
            } catch {
                // A broken host hook must never take the island down.
            }
        }
    }
    // Late pushes land immediately.
    window.YikesReady = { push: (callback: (api: YikesGlobalApi) => void) => callback(yikes) } as unknown as typeof window.YikesReady;
}

/**
 * JSON.stringify that never throws on circular references, functions,
 * symbols, or bigints. Returns undefined for values with no JSON
 * representation at all (e.g. a bare function).
 */
export function safeStringify(value: unknown): string | undefined {
    const seen = new WeakSet<object>();

    return JSON.stringify(value, (_key: string, val: unknown) => {
        if (typeof val === "function" || typeof val === "symbol") {
            return undefined;
        }
        if (typeof val === "bigint") {
            return val.toString();
        }
        if (val !== null && typeof val === "object") {
            if (seen.has(val)) {
                return "[circular]";
            }
            seen.add(val);
        }
        return val;
    });
}

/** Whether the HOST page is in dark mode (used for capture and island theming). */
export function isHostDark(): boolean {
    const selector = bootstrap().darkSelector;

    if (selector) {
        try {
            return document.querySelector(selector) !== null;
        } catch {
            // Invalid selector — fall through to auto-detection.
        }
    }

    const root = document.documentElement;
    if (root.classList.contains("dark") || root.classList.contains("app-dark")) {
        return true;
    }
    if (root.dataset.theme === "dark") {
        return true;
    }

    return window.matchMedia("(prefers-color-scheme: dark)").matches;
}

export function captureContext(): YikesContext {
    const context: YikesContext = {
        url: window.location.href,
        route: bootstrap().route,
        page: null,
        title: document.title || null,
        account: null,
        department: null,
        dark_mode: isHostDark(),
        viewport: { width: window.innerWidth, height: window.innerHeight },
        user_agent: navigator.userAgent,
        element: null,
    };

    for (const provider of contextProviders) {
        try {
            const extra = provider();
            if (extra && typeof extra === "object") {
                for (const [key, value] of Object.entries(extra)) {
                    if (value !== undefined) {
                        (context as unknown as Record<string, unknown>)[key] = value;
                    }
                }
            }
        } catch {
            // Provider errors never block a capture.
        }
    }

    return context;
}

/**
 * Snapshot host app state via the registered state providers: the first
 * provider returning a serializable non-null value wins. Size-capped with a
 * truncation marker appended. Null when nothing was captured.
 */
export function captureState(maxBytes: number = MAX_STATE_BYTES): string | null {
    for (const provider of stateProviders) {
        let serialized: string | undefined;

        try {
            serialized = safeStringify(provider());
        } catch {
            continue;
        }

        if (serialized === undefined || serialized === "null") {
            continue;
        }

        if (serialized.length > maxBytes) {
            serialized = serialized.slice(0, maxBytes) + STATE_TRUNCATION_MARKER;
        }

        return serialized;
    }

    return null;
}
