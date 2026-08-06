import { mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";
import YCopyId from "../YCopyId.vue";

const ID = "019fd7d3-e899-7351-a6cd-2f713c8fec16";

const writeText = vi.fn();

beforeEach(() => {
    writeText.mockReset();
    writeText.mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", {
        value: { writeText },
        configurable: true,
    });
});

describe("YCopyId", () => {
    it("shows the id tail and exposes the full id as the title", () => {
        const wrapper = mount(YCopyId, { props: { id: ID } });

        expect(wrapper.find("code").text()).toContain(ID.slice(-8));
        expect(wrapper.find("code").attributes("title")).toBe(ID);
    });

    it("copies the full id and confirms with a text swap", async () => {
        const wrapper = mount(YCopyId, { props: { id: ID } });

        await wrapper.find("button").trigger("click");
        await vi.waitFor(() => {
            expect(wrapper.text()).toContain("Copied");
        });

        expect(writeText).toHaveBeenCalledWith(ID);
    });

    it("reports a failed clipboard write instead of claiming success", async () => {
        writeText.mockRejectedValue(new Error("denied"));
        const wrapper = mount(YCopyId, { props: { id: ID } });

        await wrapper.find("button").trigger("click");
        await vi.waitFor(() => {
            expect(wrapper.text()).toContain("Copy failed");
        });

        expect(wrapper.text()).not.toContain("Copied");
    });
});
