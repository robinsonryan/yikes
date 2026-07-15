import { reactive } from "vue";
import { api, YikesRequestError } from "../api";
import { useYikesToast } from "../ui/toast";

/**
 * Tiny Inertia-useForm-alike over the island's fetch client: reactive
 * fields, a 422 errors bag, processing state, and a transform hook. Network
 * and server errors surface as an error toast.
 */

type Method = "post" | "patch";

export interface YikesForm<T extends Record<string, unknown>> {
    data: T;
    errors: Record<string, string>;
    processing: boolean;
    transform(callback: (data: T) => unknown): YikesForm<T>;
    submit(method: Method, url: string, options?: { onSuccess?: (response: { message?: string }) => void }): Promise<void>;
    reset(): void;
    clearErrors(): void;
}

export function useYikesForm<T extends Record<string, unknown>>(initial: T): YikesForm<T> {
    const defaults = { ...initial };
    let transformCallback: ((data: T) => unknown) | null = null;

    const form = reactive({
        data: { ...initial },
        errors: {} as Record<string, string>,
        processing: false,

        transform(callback: (data: T) => unknown) {
            transformCallback = callback;
            return form;
        },

        async submit(method: Method, url: string, options: { onSuccess?: (response: { message?: string }) => void } = {}) {
            form.processing = true;
            form.errors = {};

            try {
                const payload = transformCallback ? transformCallback(form.data as T) : form.data;
                const response = await api[method]<{ message?: string }>(url, payload);
                options.onSuccess?.(response ?? {});
            } catch (error) {
                if (error instanceof YikesRequestError && error.status === 422) {
                    form.errors = error.errors;
                } else {
                    useYikesToast().add({
                        severity: "error",
                        summary: "Request failed",
                        detail: error instanceof Error ? error.message : "Unknown error",
                        life: 5000,
                    });
                }
            } finally {
                form.processing = false;
            }
        },

        reset() {
            Object.assign(form.data, { ...defaults });
        },

        clearErrors() {
            form.errors = {};
        },
    }) as YikesForm<T>;

    return form;
}
