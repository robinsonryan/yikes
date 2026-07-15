import { createApp, type Component } from "vue";
import { bootstrap } from "./bootstrap";
import { installGlobalApi, isHostDark } from "./context";
import YikesFab from "./components/YikesFab.vue";
import IndexPage from "./pages/Index.vue";
import TestingIndexPage from "./pages/testing/Index.vue";
import TestingTesterPage from "./pages/testing/Tester.vue";
import TestingSuitePage from "./pages/testing/Suite.vue";
import TestingTestPage from "./pages/testing/Test.vue";
import YToasts from "./ui/YToasts.vue";
import stylesheet from "../css/yikes.css?inline";

/**
 * The self-contained island entry. Injected at the end of <body> on every
 * host page (InjectYikesAssets middleware) and on the package's own Blade
 * shell pages:
 *
 * - Everything mounts inside shadow roots with the package stylesheet
 *   adopted, so host CSS and island CSS never interact.
 * - `@property` rules don't register inside shadow DOM, so they are lifted
 *   into a document-level <style> once (they're global, selector-free, and
 *   harmless to the host).
 * - The FAB mounts on every page; `#yikes-app` (the Blade shell) additionally
 *   mounts the requested page component.
 */

const PAGES: Record<string, Component> = {
    "Index": IndexPage,
    "testing/Index": TestingIndexPage,
    "testing/Tester": TestingTesterPage,
    "testing/Suite": TestingSuitePage,
    "testing/Test": TestingTestPage,
};

let sharedSheet: CSSStyleSheet | null = null;

function ensureStyles(): CSSStyleSheet {
    if (sharedSheet) {
        return sharedSheet;
    }

    const propertyBlocks = stylesheet.match(/@property[^{}]+\{[^{}]*\}/g) ?? [];

    if (propertyBlocks.length > 0 && !document.getElementById("yikes-properties")) {
        const style = document.createElement("style");
        style.id = "yikes-properties";
        style.textContent = propertyBlocks.join("\n");
        document.head.append(style);
    }

    sharedSheet = new CSSStyleSheet();
    sharedSheet.replaceSync(stylesheet);

    return sharedSheet;
}

/** Keep the island's `.y-dark` container class in sync with the host theme. */
function watchDarkMode(container: HTMLElement): void {
    function apply(): void {
        container.classList.toggle("y-dark", isHostDark());
    }

    apply();

    const observer = new MutationObserver(apply);
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class", "data-theme"] });
    if (document.body) {
        observer.observe(document.body, { attributes: true, attributeFilter: ["class", "data-theme"] });
    }
    window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", apply);
}

interface MountedIsland {
    host: HTMLElement;
    container: HTMLElement;
}

function createIsland(host: HTMLElement): MountedIsland {
    const shadow = host.attachShadow({ mode: "open" });
    shadow.adoptedStyleSheets = [ensureStyles()];

    const container = document.createElement("div");
    container.className = "yikes-root font-sans";
    shadow.append(container);

    watchDarkMode(container);

    return { host, container };
}

function mountApp(root: Component, props: Record<string, unknown>, island: MountedIsland): void {
    const app = createApp(root, props);
    app.provide("yikes:shadowHost", island.host);
    app.provide("yikes:container", island.container);
    app.mount(island.container);

    // Every island renders its own toast outlet; the store is shared.
    const toastMount = document.createElement("div");
    island.container.append(toastMount);
    const toastApp = createApp(YToasts);
    toastApp.mount(toastMount);
}

function boot(): void {
    if (window.__YIKES_MOUNTED__) {
        return;
    }
    window.__YIKES_MOUNTED__ = true;

    bootstrap(); // Throws early (and loudly) if the config script is missing.
    installGlobalApi();

    // Package-served page (Blade shell)?
    const pageEl = document.getElementById("yikes-app");

    if (pageEl instanceof HTMLElement) {
        const component = PAGES[pageEl.dataset.component ?? ""];
        const props = JSON.parse(pageEl.dataset.props ?? "{}") as Record<string, unknown>;

        if (component) {
            mountApp(component, props, createIsland(pageEl));
        }
    }

    // The FAB exists on every page, host and package alike.
    const fabHost = document.createElement("div");
    fabHost.id = "yikes-fab";
    document.body.append(fabHost);
    mountApp(YikesFab, {}, createIsland(fabHost));
}

if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", boot);
} else {
    boot();
}
