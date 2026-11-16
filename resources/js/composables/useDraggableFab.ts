import { onBeforeUnmount, onMounted, type Ref } from "vue";
import { draggable, type Draggable } from "@robinsonryan/capture";

const STORAGE_KEY = "yikes.fab.position";

/**
 * Vue wrapper over the capture kit's `draggable` for the yikes FAB pill: the
 * pill can be moved anywhere in the viewport, its position persists per browser
 * and is clamped back into view on restore and on resize. Bind
 * `onHandlePointerDown` to the grip (never the action buttons, so taps stay
 * taps). Until the first drag the pill keeps its CSS default (bottom-right).
 */
export function useDraggableFab(el: Ref<HTMLElement | null>, storageKey: string = STORAGE_KEY) {
    let instance: Draggable | null = null;

    onMounted(() => {
        if (el.value) {
            instance = draggable(el.value, { storageKey });
        }
    });

    onBeforeUnmount(() => {
        instance?.destroy();
        instance = null;
    });

    function onHandlePointerDown(event: PointerEvent): void {
        instance?.onHandlePointerDown(event);
    }

    return { onHandlePointerDown };
}

export default useDraggableFab;
