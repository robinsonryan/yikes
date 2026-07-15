/**
 * Typed accessor for the server-injected `window.__YIKES__` bootstrap object
 * (built by RobinsonRyan\Yikes\Support\YikesAssets::injectHtml).
 */

export interface YikesBootstrap {
    /** Absolute base URL of the notes surface, e.g. "https://app.test/yikes". */
    base: string;
    /** Absolute base URL of the checklist surface, e.g. "https://app.test/testing". */
    testingBase: string;
    csrf: string;
    /** Laravel route name of the current page, if any. */
    route: string | null;
    /** Host dark-mode selector from config, e.g. ".app-dark"; null = auto-detect. */
    darkSelector: string | null;
    name: string;
}

declare global {
    interface Window {
        __YIKES__?: YikesBootstrap;
        __YIKES_MOUNTED__?: boolean;
    }
}

export function bootstrap(): YikesBootstrap {
    const config = window.__YIKES__;

    if (!config) {
        throw new Error("yikes: window.__YIKES__ is missing — the island was loaded without its bootstrap.");
    }

    return config;
}
