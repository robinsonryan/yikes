import { beforeEach, describe, expect, it } from "vitest";
import {
    captureContext,
    captureState,
    installGlobalApi,
    MAX_STATE_BYTES,
    safeStringify,
    STATE_TRUNCATION_MARKER,
} from "../../context";

/**
 * The island installs `window.Yikes` in its bootstrap; these specs drive it
 * through that public surface. Narrow once here rather than asserting non-null
 * at every call site — if setup ever stops installing it, the failure names the
 * cause instead of surfacing as a bare TypeError.
 */
function yikesApi(): NonNullable<typeof window.Yikes> {
    const api = window.Yikes;

    if (!api) {
        throw new Error("window.Yikes is not installed — check the spec's setup.");
    }

    return api;
}

describe("yikes context capture", () => {
    beforeEach(() => {
        window.__YIKES__ = {
            base: "http://localhost/yikes",
            testingBase: "http://localhost/testing",
            csrf: "token",
            route: "some.route",
            darkSelector: null,
            name: "Test App",
        };
        window.Yikes = undefined;
        window.YikesReady = undefined;
        document.documentElement.className = "";
        installGlobalApi();
    });

    it("captures core context without any providers", () => {
        const context = captureContext();

        expect(context.route).toBe("some.route");
        expect(context.page).toBeNull();
        expect(context.account).toBeNull();
        expect(context.viewport?.width).toBeGreaterThan(0);
        expect(typeof context.dark_mode).toBe("boolean");
    });

    it("merges registered context providers over the core context", () => {
        yikesApi().registerContextProvider(() => ({
            page: "billing/Index",
            account: { id: "1", name: "Acme" },
        }));

        const context = captureContext();

        expect(context.page).toBe("billing/Index");
        expect(context.account).toEqual({ id: "1", name: "Acme" });
        expect(context.route).toBe("some.route");
    });

    it("ignores throwing providers", () => {
        yikesApi().registerContextProvider(() => {
            throw new Error("boom");
        });
        yikesApi().registerContextProvider(() => ({ page: "still-works" }));

        expect(captureContext().page).toBe("still-works");
    });

    it("honors the pre-island YikesReady queue", () => {
        window.Yikes = undefined;
        window.YikesReady = [
            (yikes) => yikes.registerContextProvider(() => ({ page: "queued" })),
        ];

        installGlobalApi();

        expect(captureContext().page).toBe("queued");
    });

    it("returns null state without providers", () => {
        expect(captureState()).toBeNull();
    });

    it("serializes the first non-null state provider", () => {
        yikesApi().registerStateProvider(() => null);
        yikesApi().registerStateProvider(() => ({ cart: { items: 2 } }));

        expect(captureState()).toBe('{"cart":{"items":2}}');
    });

    it("caps oversized state with the truncation marker", () => {
        yikesApi().registerStateProvider(() => ({ blob: "x".repeat(MAX_STATE_BYTES + 100) }));

        const state = captureState();

        expect(state).not.toBeNull();

        if (state === null) {
            throw new Error("captureState() returned null after a state provider was registered.");
        }

        expect(state.endsWith(STATE_TRUNCATION_MARKER)).toBe(true);
        expect(state.length).toBeLessThanOrEqual(MAX_STATE_BYTES + STATE_TRUNCATION_MARKER.length);
    });

    it("safeStringify survives circular references and functions", () => {
        const circular: Record<string, unknown> = { name: "a" };
        circular.self = circular;
        circular.fn = () => 1;

        expect(safeStringify(circular)).toBe('{"name":"a","self":"[circular]"}');
    });
});
