import { computed, onBeforeUnmount, onMounted, ref, type CSSProperties, type Ref } from "vue";

interface Point {
    x: number;
    y: number;
}

const STORAGE_KEY = "yikes.fab.position";
const EDGE_MARGIN = 8;

/**
 * Drag behavior for the yikes FAB pill: the fixed pill can be moved anywhere
 * in the viewport (it inevitably covers SOME page element wherever it sits, so
 * the user decides where it's least in the way). The position persists in
 * localStorage and is clamped back into view on restore and on window resize.
 *
 * `el` is the pill element; dragging starts from the dedicated grip handle
 * (never the action buttons, so taps stay taps). While `style` is empty the
 * pill keeps its CSS default (bottom-right).
 */
export function useDraggableFab(el: Ref<HTMLElement | null>, storageKey: string = STORAGE_KEY) {
    const position = ref<Point | null>(null);

    const style = computed<CSSProperties>(() =>
        position.value
            ? {
                  left: `${position.value.x}px`,
                  top: `${position.value.y}px`,
                  right: "auto",
                  bottom: "auto",
              }
            : {}
    );

    function clamp(p: Point): Point {
        const rect = el.value?.getBoundingClientRect();
        const maxX = window.innerWidth - (rect?.width ?? 0) - EDGE_MARGIN;
        const maxY = window.innerHeight - (rect?.height ?? 0) - EDGE_MARGIN;
        return {
            x: Math.min(Math.max(p.x, EDGE_MARGIN), Math.max(EDGE_MARGIN, maxX)),
            y: Math.min(Math.max(p.y, EDGE_MARGIN), Math.max(EDGE_MARGIN, maxY)),
        };
    }

    function persist(): void {
        if (!position.value) {
            return;
        }
        try {
            localStorage.setItem(storageKey, JSON.stringify(position.value));
        } catch {
            // Storage unavailable (private mode/quota) — the drag still works
            // for the session; it just won't stick.
        }
    }

    function restore(): void {
        try {
            const raw = localStorage.getItem(storageKey);
            if (!raw) {
                return;
            }
            const p = JSON.parse(raw) as Partial<Point> | null;
            if (typeof p?.x === "number" && typeof p?.y === "number") {
                position.value = clamp({ x: p.x, y: p.y });
            }
        } catch {
            // Corrupt value — fall back to the CSS default position.
        }
    }

    let dragOffset: Point | null = null;

    function onPointerMove(event: PointerEvent): void {
        if (!dragOffset) {
            return;
        }
        position.value = clamp({
            x: event.clientX - dragOffset.x,
            y: event.clientY - dragOffset.y,
        });
    }

    function endDrag(): void {
        if (!dragOffset) {
            return;
        }
        dragOffset = null;
        window.removeEventListener("pointermove", onPointerMove);
        window.removeEventListener("pointerup", endDrag);
        window.removeEventListener("pointercancel", endDrag);
        persist();
    }

    function onHandlePointerDown(event: PointerEvent): void {
        const rect = el.value?.getBoundingClientRect();
        if (!rect) {
            return;
        }
        event.preventDefault();
        dragOffset = { x: event.clientX - rect.left, y: event.clientY - rect.top };
        window.addEventListener("pointermove", onPointerMove);
        window.addEventListener("pointerup", endDrag);
        window.addEventListener("pointercancel", endDrag);
    }

    function onWindowResize(): void {
        if (position.value) {
            position.value = clamp(position.value);
        }
    }

    onMounted(() => {
        restore();
        window.addEventListener("resize", onWindowResize);
    });

    onBeforeUnmount(() => {
        window.removeEventListener("resize", onWindowResize);
        endDrag();
    });

    return { style, position, onHandlePointerDown };
}

export default useDraggableFab;
