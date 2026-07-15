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
    /** True when the host pushes notes to a yikes hub (no local index/triage UI). */
    hub: boolean;
    /** Per-screenshot byte cap — captures above it are downscaled client-side. */
    maxScreenshotBytes: number;
}

/**
 * What the server actually injects. The hub fields are optional on the wire
 * so pages cached from a pre-hub package version still boot; bootstrap()
 * fills in their defaults.
 */
type YikesBootstrapWire = Omit<YikesBootstrap, "hub" | "maxScreenshotBytes"> &
    Partial<Pick<YikesBootstrap, "hub" | "maxScreenshotBytes">>;

declare global {
    interface Window {
        __YIKES__?: YikesBootstrapWire;
        __YIKES_MOUNTED__?: boolean;
    }
}

export function bootstrap(): YikesBootstrap {
    const config = window.__YIKES__;

    if (!config) {
        throw new Error("yikes: window.__YIKES__ is missing — the island was loaded without its bootstrap.");
    }

    return { hub: false, maxScreenshotBytes: 4096 * 1024, ...config };
}
