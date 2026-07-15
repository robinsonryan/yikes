import { describe, it, expect, beforeEach, vi } from "vitest";
import { defineComponent, h, ref, type Ref } from "vue";
import { mount } from "@vue/test-utils";
import { useDraggableFab } from "../useDraggableFab";

const STORAGE_KEY = "yikes.fab.position";

type Harness = ReturnType<typeof useDraggableFab> & { el: Ref<HTMLElement | null> };

function mountHarness(): Harness {
    let exposed: Harness | null = null;

    const Comp = defineComponent({
        setup() {
            const el = ref<HTMLElement | null>(null);
            exposed = { ...useDraggableFab(el), el };
            return () => h("div", { ref: el });
        },
    });

    mount(Comp, { attachTo: document.body });
    if (!exposed) {
        throw new Error("harness setup did not run");
    }
    return exposed;
}

function pointerEvent(type: string, x: number, y: number): Event {
    // jsdom has no PointerEvent constructor; MouseEvent carries the same
    // clientX/clientY the composable reads.
    return new MouseEvent(type, { clientX: x, clientY: y, bubbles: true });
}

describe("useDraggableFab", () => {
    beforeEach(() => {
        localStorage.clear();
        vi.stubGlobal("innerWidth", 800);
        vi.stubGlobal("innerHeight", 600);
    });

    it("keeps the CSS default position until dragged", () => {
        const fab = mountHarness();
        expect(fab.style.value).toEqual({});
        expect(fab.position.value).toBeNull();
    });

    it("follows the pointer during a drag and persists the drop point", () => {
        const fab = mountHarness();

        fab.onHandlePointerDown(pointerEvent("pointerdown", 100, 100) as PointerEvent);
        window.dispatchEvent(pointerEvent("pointermove", 150, 220));

        // The jsdom element rect is at 0,0 — the pointer offset is 100,100.
        expect(fab.position.value).toEqual({ x: 50, y: 120 });
        expect(fab.style.value.left).toBe("50px");
        expect(fab.style.value.right).toBe("auto");

        window.dispatchEvent(pointerEvent("pointerup", 150, 220));
        expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "null")).toEqual({ x: 50, y: 120 });

        // Listeners are removed after the drag ends.
        window.dispatchEvent(pointerEvent("pointermove", 400, 400));
        expect(fab.position.value).toEqual({ x: 50, y: 120 });
    });

    it("clamps the position inside the viewport", () => {
        const fab = mountHarness();

        fab.onHandlePointerDown(pointerEvent("pointerdown", 0, 0) as PointerEvent);
        window.dispatchEvent(pointerEvent("pointermove", -500, 9999));
        window.dispatchEvent(pointerEvent("pointerup", -500, 9999));

        expect(fab.position.value?.x).toBeGreaterThanOrEqual(8);
        expect(fab.position.value?.y).toBeLessThanOrEqual(600 - 8);
    });

    it("restores a persisted position on mount", () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ x: 40, y: 60 }));

        const fab = mountHarness();

        expect(fab.position.value).toEqual({ x: 40, y: 60 });
        expect(fab.style.value.top).toBe("60px");
    });

    it("ignores a corrupt persisted value", () => {
        localStorage.setItem(STORAGE_KEY, "not json{");

        const fab = mountHarness();

        expect(fab.position.value).toBeNull();
    });
});
