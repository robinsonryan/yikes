import { describe, expect, it } from "vitest";
import { checklistTextHtml, checklistTextPlain } from "../checklistText";

describe("checklistTextHtml", () => {
    it("renders markdown-style links as new-tab anchors", () => {
        const html = checklistTextHtml("Open the [Users page](/account/users) and look around.");

        expect(html).toContain(
            '<a href="/account/users" target="_blank" rel="noopener noreferrer"'
        );
        expect(html).toContain(">Users page</a>");
        expect(html).toContain("and look around.");
    });

    it("allows absolute http(s) urls", () => {
        expect(checklistTextHtml("[docs](https://example.com/a)")).toContain(
            'href="https://example.com/a"'
        );
    });

    it("leaves unsafe hrefs as literal text", () => {
        const html = checklistTextHtml("[bad](javascript:alert(1))");

        expect(html).not.toContain("<a");
        expect(html).toContain("[bad](javascript:alert(1))");
    });

    it("escapes html in the surrounding text", () => {
        const html = checklistTextHtml('<script>alert("x")</script> then [go](/login)');

        expect(html).not.toContain("<script>");
        expect(html).toContain("&lt;script&gt;");
        expect(html).toContain('<a href="/login"');
    });

    it("handles multiple links in one step", () => {
        const html = checklistTextHtml("Visit [a](/a) then [b](/b).");

        expect(html).toContain('href="/a"');
        expect(html).toContain('href="/b"');
    });
});

describe("checklistTextPlain", () => {
    it("collapses links to their labels", () => {
        expect(checklistTextPlain("Open the [Users page](/account/users) now.")).toBe(
            "Open the Users page now."
        );
    });

    it("passes plain text through", () => {
        expect(checklistTextPlain("No links here.")).toBe("No links here.");
    });
});
