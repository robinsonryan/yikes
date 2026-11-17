import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import YikesFab from "../YikesFab.vue";

/**
 * The pill's calls into the capture kit: what yikes asks of `pick`, `capture` and
 * `fitUnderCap`, and what it does with the answers. The kit itself is tested in
 * its own package.
 */
const kit = vi.hoisted(() => ({
    pick: vi.fn(),
    capture: vi.fn(),
    fitUnderCap: vi.fn(),
    draggable: vi.fn(() => ({ position: () => null, onHandlePointerDown: vi.fn(), destroy: vi.fn() })),
}));

vi.mock("@robinsonryan/capture", () => kit);

vi.mock("../../api", () => ({
    api: { postForm: vi.fn() },
    noteUrl: (path = ""): string => `/yikes${path}`,
}));

vi.mock("../../bootstrap", () => ({
    bootstrap: () => ({ hub: false, maxScreenshotBytes: 1234 }),
}));

import { api } from "../../api";

const postForm = vi.mocked(api.postForm);
const shot = new Blob(["png"], { type: "image/png" });
const capped = new Blob(["small"], { type: "image/png" });

let shadowHost: HTMLElement;
let container: HTMLElement;

function mountFab() {
    return mount(YikesFab, {
        attachTo: document.body,
        global: {
            provide: { "yikes:shadowHost": shadowHost, "yikes:container": container },
            stubs: { YikesDialog: true },
        },
    });
}

function button(wrapper: ReturnType<typeof mountFab>, label: string) {
    const found = wrapper.find(`[aria-label="${label}"]`);
    if (!found.exists()) {
        throw new Error(`No "${label}" button rendered`);
    }
    return found;
}

beforeEach(() => {
    document.body.innerHTML = "";
    shadowHost = document.createElement("div");
    container = document.createElement("div");
    document.body.append(shadowHost, container);
    kit.pick.mockReset();
    kit.capture.mockReset().mockResolvedValue(shot);
    kit.fitUnderCap.mockReset().mockResolvedValue(capped);
    postForm.mockReset().mockResolvedValue({ id: "shot-1" });
});

describe("YikesFab — picking an element", () => {
    it("picks one element, never its own UI, with its chrome in the island and yikes's hint", async () => {
        kit.pick.mockReturnValue(new Promise(() => undefined));
        const wrapper = mountFab();

        await button(wrapper, "Pick a page element for a yikes note").trigger("click");

        expect(kit.pick).toHaveBeenCalledOnce();
        expect(kit.pick).toHaveBeenCalledWith({
            max: 1,
            exclude: [shadowHost],
            overlayHost: container,
            hint: "Click an element to attach your note to it — Esc to cancel",
        });
        // The pill hides while picking so it cannot be picked.
        await wrapper.vm.$nextTick();
        expect((wrapper.element.firstElementChild as HTMLElement).style.display).toBe("none");
        wrapper.unmount();
    });

    it("captures the picked element, caps it, uploads it and opens the note with its context", async () => {
        const element = document.createElement("h1");
        kit.pick.mockResolvedValue([{ element, selector: "#headline", tag: "h1", text: "Big headline" }]);
        const wrapper = mountFab();

        await button(wrapper, "Pick a page element for a yikes note").trigger("click");
        await flushPromises();

        expect(kit.capture).toHaveBeenCalledWith({ mode: "element", element });
        expect(kit.fitUnderCap).toHaveBeenCalledWith(shot, 1234);
        const form = postForm.mock.calls[0]?.[1];
        expect(postForm.mock.calls[0]?.[0]).toBe("/yikes/screenshots");
        expect(form?.get("screenshot")).toBeInstanceOf(Blob);
        const dialog = wrapper.findComponent({ name: "YikesDialog" });
        expect(dialog.props("visible")).toBe(true);
        expect(dialog.props("element")).toEqual({ selector: "#headline", tag: "h1", text: "Big headline" });
        wrapper.unmount();
    });

    it("does nothing more when the pick is cancelled", async () => {
        kit.pick.mockResolvedValue([]);
        const wrapper = mountFab();

        await button(wrapper, "Pick a page element for a yikes note").trigger("click");
        await flushPromises();

        expect(kit.capture).not.toHaveBeenCalled();
        expect(wrapper.findComponent({ name: "YikesDialog" }).props("visible")).toBe(false);
        wrapper.unmount();
    });
});

describe("YikesFab — snapping a screenshot", () => {
    it("captures the whole page, caps it and uploads it as a pending screenshot", async () => {
        const wrapper = mountFab();

        await button(wrapper, "Snap a screenshot for a yikes note").trigger("click");
        await flushPromises();

        expect(kit.capture).toHaveBeenCalledWith({ mode: "full" });
        expect(kit.fitUnderCap).toHaveBeenCalledWith(shot, 1234);
        expect(postForm).toHaveBeenCalledOnce();
        expect(wrapper.find('span[aria-hidden="true"]').text()).toBe("1");
        wrapper.unmount();
    });
});
