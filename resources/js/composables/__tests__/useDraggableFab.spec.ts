import { describe, it, expect, beforeEach, vi } from "vitest";
import { defineComponent, h, ref } from "vue";
import { mount } from "@vue/test-utils";
import { useDraggableFab } from "../useDraggableFab";

const STORAGE_KEY = "yikes.fab.position";

/** The drag behaviour itself is specified in the capture kit; this covers the Vue wiring. */
function mountHarness() {
    let exposed: ReturnType<typeof useDraggableFab> | null = null;

    const Comp = defineComponent({
        setup() {
            const el = ref<HTMLElement | null>(null);
            exposed = useDraggableFab(el);
            return () => h("div", { ref: el });
        },
    });

    const wrapper = mount(Comp, { attachTo: document.body });
    if (!exposed) {
        throw new Error("harness setup did not run");
    }
    return { fab: exposed as ReturnType<typeof useDraggableFab>, wrapper, pill: wrapper.element as HTMLElement };
}

function pointerEvent(type: string, x: number, y: number): PointerEvent {
    // happy-dom's MouseEvent carries the same clientX/clientY the kit reads.
    return new MouseEvent(type, { clientX: x, clientY: y, bubbles: true }) as PointerEvent;
}

describe("useDraggableFab", () => {
    beforeEach(() => {
        localStorage.clear();
        vi.stubGlobal("innerWidth", 800);
        vi.stubGlobal("innerHeight", 600);
    });

    it("keeps the CSS default position until dragged", () => {
        const { pill } = mountHarness();

        expect(pill.style.left).toBe("");
        expect(pill.style.top).toBe("");
    });

    it("moves the pill from the grip and remembers it under the yikes key", () => {
        const { fab, pill } = mountHarness();

        fab.onHandlePointerDown(pointerEvent("pointerdown", 100, 100));
        window.dispatchEvent(pointerEvent("pointermove", 150, 220));
        window.dispatchEvent(pointerEvent("pointerup", 150, 220));

        expect(pill.style.left).toBe("50px");
        expect(pill.style.top).toBe("120px");
        expect(JSON.parse(localStorage.getItem(STORAGE_KEY) ?? "null")).toEqual({ x: 50, y: 120 });
    });

    it("clamps the pill inside the viewport", () => {
        const { fab, pill } = mountHarness();

        fab.onHandlePointerDown(pointerEvent("pointerdown", 0, 0));
        window.dispatchEvent(pointerEvent("pointermove", -500, 9999));
        window.dispatchEvent(pointerEvent("pointerup", -500, 9999));

        expect(pill.style.left).toBe("8px");
        expect(pill.style.top).toBe(`${600 - 8}px`);
    });

    it("restores a persisted position on mount", () => {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({ x: 40, y: 60 }));

        const { pill } = mountHarness();

        expect(pill.style.top).toBe("60px");
    });

    it("ignores a corrupt persisted value", () => {
        localStorage.setItem(STORAGE_KEY, "not json{");

        const { pill } = mountHarness();

        expect(pill.style.left).toBe("");
    });

    it("stops dragging once unmounted", () => {
        const { fab, pill, wrapper } = mountHarness();

        fab.onHandlePointerDown(pointerEvent("pointerdown", 0, 0));
        wrapper.unmount();
        window.dispatchEvent(pointerEvent("pointermove", 300, 300));

        expect(pill.style.left).toBe("");
    });
});
