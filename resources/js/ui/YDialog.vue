<script setup lang="ts">
import { onBeforeUnmount, watch } from "vue";
import YButton from "./YButton.vue";

/**
 * Modal dialog rendered in place (fixed positioning escapes any layout since
 * the island root is untransformed). Full-screen below `sm`, width-capped
 * above — the host brand convention, kept.
 */
const props = withDefaults(
    defineProps<{
        header: string;
        /** Tailwind max-width class applied from sm: up. */
        width?: string;
    }>(),
    { width: "sm:max-w-lg" },
);

const visible = defineModel<boolean>("visible", { default: false });

function close(): void {
    visible.value = false;
}

function onKeydown(event: KeyboardEvent): void {
    if (event.key === "Escape") {
        close();
    }
}

watch(
    visible,
    (open) => {
        if (open) {
            document.addEventListener("keydown", onKeydown);
        } else {
            document.removeEventListener("keydown", onKeydown);
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => document.removeEventListener("keydown", onKeydown));
</script>

<template>
    <div v-if="visible" class="fixed inset-0 z-[999990] flex items-stretch justify-center sm:items-center sm:p-4">
        <div class="absolute inset-0 bg-black/40" aria-hidden="true" @click="close"></div>
        <div
            role="dialog"
            aria-modal="true"
            class="relative flex w-full flex-col bg-surface-0 text-surface-900 shadow-xl dark:bg-surface-800 dark:text-surface-100 sm:max-h-[90vh] sm:rounded-lg"
            :class="props.width"
        >
            <div class="flex items-center justify-between gap-4 px-5 pt-4 pb-2">
                <h2 class="text-lg font-semibold">{{ header }}</h2>
                <YButton icon="x" text variant="secondary" size="sm" aria-label="Close dialog" @click="close" />
            </div>
            <div class="min-h-0 grow overflow-y-auto px-5 pb-5">
                <slot />
            </div>
        </div>
    </div>
</template>
