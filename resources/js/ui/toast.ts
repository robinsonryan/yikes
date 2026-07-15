import { ref } from "vue";

/**
 * Module-scoped toast store — every mounted island app (FAB, page) renders a
 * YToasts outlet and they all share this list.
 */

export interface YikesToast {
    id: number;
    severity: "success" | "error" | "info";
    summary: string;
    detail?: string;
}

let nextId = 1;

export const toasts = ref<YikesToast[]>([]);

export function useYikesToast() {
    function add(toast: Omit<YikesToast, "id"> & { life?: number }): void {
        const { life = 3500, ...data } = toast;
        const entry: YikesToast = { id: nextId++, ...data };

        toasts.value.push(entry);
        setTimeout(() => dismiss(entry.id), life);
    }

    function dismiss(id: number): void {
        toasts.value = toasts.value.filter((toast) => toast.id !== id);
    }

    return { add, dismiss };
}
