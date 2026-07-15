import type { YikesElementContext } from "../types";
import { buildSelector, elementText } from "../utils/selector";

/**
 * Element pick mode: hover highlights host-page elements, click selects,
 * Escape cancels. The highlight/hint chrome lives in the island's shadow
 * container with pointer-events off, so document.elementFromPoint always
 * resolves host elements; all interception happens via capture-phase
 * listeners on the document.
 */

export interface PickedElement {
    element: HTMLElement;
    context: YikesElementContext;
}

const IGNORED_TAGS = new Set(["html", "body"]);

export function pickElement(overlayHost: HTMLElement, shadowHost: HTMLElement): Promise<PickedElement | null> {
    return new Promise((resolve) => {
        const highlight = document.createElement("div");
        highlight.style.cssText =
            "position:fixed;pointer-events:none;z-index:999980;border:2px solid #2563eb;background:rgba(37,99,235,0.12);border-radius:3px;display:none;transition:all 60ms linear;";

        const label = document.createElement("div");
        label.style.cssText =
            "position:fixed;pointer-events:none;z-index:999981;background:#1e293b;color:#fff;font:11px/1.6 ui-monospace,monospace;padding:1px 6px;border-radius:3px;display:none;max-width:60vw;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;";

        const hint = document.createElement("div");
        hint.textContent = "Click an element to attach your note to it — Esc to cancel";
        hint.style.cssText =
            "position:fixed;left:50%;bottom:16px;transform:translateX(-50%);pointer-events:none;z-index:999982;background:#1e293b;color:#fff;font:12px/1.4 ui-sans-serif,system-ui;padding:6px 12px;border-radius:9999px;box-shadow:0 4px 12px rgba(0,0,0,0.3);";

        overlayHost.append(highlight, label, hint);

        let current: HTMLElement | null = null;
        const previousCursor = document.documentElement.style.cursor;
        document.documentElement.style.cursor = "crosshair";

        function targetAt(x: number, y: number): HTMLElement | null {
            const element = document.elementFromPoint(x, y);

            if (!element || element === shadowHost || shadowHost.contains(element)) {
                return null;
            }
            if (IGNORED_TAGS.has(element.localName)) {
                return null;
            }

            return element as HTMLElement;
        }

        function onMove(event: MouseEvent): void {
            current = targetAt(event.clientX, event.clientY);

            if (!current) {
                highlight.style.display = "none";
                label.style.display = "none";
                return;
            }

            const rect = current.getBoundingClientRect();
            highlight.style.display = "block";
            highlight.style.left = `${rect.left - 2}px`;
            highlight.style.top = `${rect.top - 2}px`;
            highlight.style.width = `${rect.width + 4}px`;
            highlight.style.height = `${rect.height + 4}px`;

            label.style.display = "block";
            label.textContent = buildSelector(current);
            label.style.left = `${Math.max(4, rect.left)}px`;
            label.style.top = `${rect.top > 28 ? rect.top - 24 : rect.bottom + 4}px`;
        }

        function finish(picked: PickedElement | null): void {
            document.removeEventListener("mousemove", onMove, true);
            document.removeEventListener("click", onClick, true);
            document.removeEventListener("mousedown", swallow, true);
            document.removeEventListener("mouseup", swallow, true);
            document.removeEventListener("keydown", onKeydown, true);
            document.documentElement.style.cursor = previousCursor;
            highlight.remove();
            label.remove();
            hint.remove();
            resolve(picked);
        }

        function swallow(event: Event): void {
            event.preventDefault();
            event.stopPropagation();
        }

        function onClick(event: MouseEvent): void {
            swallow(event);

            const element = targetAt(event.clientX, event.clientY);

            if (!element) {
                return;
            }

            finish({
                element,
                context: {
                    selector: buildSelector(element),
                    tag: element.localName,
                    text: elementText(element),
                },
            });
        }

        function onKeydown(event: KeyboardEvent): void {
            if (event.key === "Escape") {
                swallow(event);
                finish(null);
            }
        }

        document.addEventListener("mousemove", onMove, true);
        document.addEventListener("click", onClick, true);
        document.addEventListener("mousedown", swallow, true);
        document.addEventListener("mouseup", swallow, true);
        document.addEventListener("keydown", onKeydown, true);
    });
}
