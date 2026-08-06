import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";
import YikesCreateDialog from "../YikesCreateDialog.vue";

vi.mock("../../api", () => ({
    api: { post: vi.fn() },
    noteUrl: (path = ""): string => `/yikes${path}`,
    YikesRequestError: class extends Error {
        status = 0;
        errors: Record<string, string> = {};
    },
}));

import { api } from "../../api";

const postMock = vi.mocked(api.post);

async function mountOpenDialog() {
    const wrapper = mount(YikesCreateDialog, {
        props: { visible: true },
    });
    await nextTick();
    return wrapper;
}

function footerButton(wrapper: Awaited<ReturnType<typeof mountOpenDialog>>, label: string) {
    const button = wrapper.findAll("button").find((b) => b.text().includes(label));
    if (!button) {
        throw new Error(`No "${label}" button rendered`);
    }
    return button;
}

/** The submitted note payload from the last api.post call. */
function lastPayload(): Record<string, unknown> {
    const call = postMock.mock.calls.at(-1);
    if (!call) {
        throw new Error("api.post was never called");
    }
    return call[1] as Record<string, unknown>;
}

describe("YikesCreateDialog footer actions", () => {
    beforeEach(() => {
        postMock.mockReset();
        postMock.mockResolvedValue({});
    });

    it("renders Cancel, Save note, and Fast track", async () => {
        const wrapper = await mountOpenDialog();

        for (const label of ["Cancel", "Save note", "Fast track"]) {
            expect(footerButton(wrapper, label).exists()).toBe(true);
        }
    });

    it("saves a note as new", async () => {
        const wrapper = await mountOpenDialog();

        await wrapper.find("textarea").setValue("A plain note");
        await wrapper.find("form").trigger("submit");

        expect(postMock).toHaveBeenCalledWith("/yikes/notes", expect.anything());
        expect(lastPayload()).toMatchObject({ body: "A plain note", status: "new" });
    });

    it("fast-tracks a note as approved", async () => {
        const wrapper = await mountOpenDialog();

        await wrapper.find("textarea").setValue("Straight to the queue");
        await footerButton(wrapper, "Fast track").trigger("click");

        expect(lastPayload()).toMatchObject({ body: "Straight to the queue", status: "approved" });
    });

    it("cancel closes without saving", async () => {
        const wrapper = await mountOpenDialog();

        await footerButton(wrapper, "Cancel").trigger("click");

        expect(postMock).not.toHaveBeenCalled();
        expect(wrapper.emitted("update:visible")?.at(-1)).toEqual([false]);
    });
});
