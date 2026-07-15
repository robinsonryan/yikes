/**
 * Best-effort unique CSS selector for a picked element: a unique #id wins;
 * otherwise a short `tag.class:nth-of-type` path from the nearest id-anchored
 * (or body) ancestor.
 */

const CLASS_LIMIT = 2;
const DEPTH_LIMIT = 6;

function isUsableClass(name: string): boolean {
    // Skip framework state/hash-y classes; keep short semantic ones.
    return /^[a-zA-Z][a-zA-Z0-9_-]*$/.test(name) && name.length <= 40;
}

function segmentFor(element: Element): string {
    let segment = element.localName;

    const classes = [...element.classList].filter(isUsableClass).slice(0, CLASS_LIMIT);
    if (classes.length > 0) {
        segment += "." + classes.map((name) => CSS.escape(name)).join(".");
    }

    const parent = element.parentElement;
    if (parent) {
        const sameTag = [...parent.children].filter((sibling) => sibling.localName === element.localName);
        if (sameTag.length > 1) {
            segment += `:nth-of-type(${sameTag.indexOf(element) + 1})`;
        }
    }

    return segment;
}

export function buildSelector(element: Element): string {
    if (element.id && document.querySelectorAll(`#${CSS.escape(element.id)}`).length === 1) {
        return `#${CSS.escape(element.id)}`;
    }

    const segments: string[] = [];
    let node: Element | null = element;

    while (node && node !== document.body && segments.length < DEPTH_LIMIT) {
        if (node.id && document.querySelectorAll(`#${CSS.escape(node.id)}`).length === 1) {
            segments.unshift(`#${CSS.escape(node.id)}`);
            return segments.join(" > ");
        }

        segments.unshift(segmentFor(node));
        node = node.parentElement;
    }

    return segments.join(" > ");
}

/** Trimmed, whitespace-collapsed visible text of the element, capped. */
export function elementText(element: Element, max = 400): string | null {
    const text = (element as HTMLElement).innerText?.replace(/\s+/g, " ").trim() ?? "";

    if (text === "") {
        return null;
    }

    return text.length > max ? text.slice(0, max) + "…" : text;
}
