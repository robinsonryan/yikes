/**
 * Rendering helpers for authored checklist text.
 *
 * Step/goal text supports markdown-style links — `[Users page](/account/users)` —
 * which render as anchors that open in a new tab, so testers keep the
 * checklist open while they work. Everything else is HTML-escaped; only
 * same-site paths and http(s) URLs are allowed as hrefs.
 */

const LINK_PATTERN = /\[([^\]]+)\]\(([^)\s]+)\)/g;

function escapeHtml(text: string): string {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

function isSafeHref(href: string): boolean {
    return href.startsWith("/") || href.startsWith("http://") || href.startsWith("https://");
}

/** Escaped HTML with `[label](url)` links as new-tab anchors. */
export function checklistTextHtml(text: string): string {
    return escapeHtml(text).replace(LINK_PATTERN, (match, label: string, href: string) => {
        if (!isSafeHref(href)) {
            return match;
        }

        return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 underline dark:text-primary-400">${label}</a>`;
    });
}

/** Plain text with `[label](url)` collapsed to just the label (for contexts already inside a link). */
export function checklistTextPlain(text: string): string {
    return text.replace(LINK_PATTERN, "$1");
}
