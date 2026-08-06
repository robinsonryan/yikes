<script setup lang="ts">
import { onBeforeUnmount, ref } from "vue";
import YIcon from "./YIcon.vue";

/**
 * Note id with a copy button. Displays the tail (the distinctive part of a
 * UUIDv7 — the head is a timestamp shared by same-day notes); the button
 * always copies the complete id. Copy feedback is a text swap — "Copied",
 * or "Copy failed" when the clipboard is unavailable — never color alone.
 */
const props = defineProps<{ id: string }>();

const copied = ref(false);
const failed = ref(false);
let resetTimer: ReturnType<typeof setTimeout> | null = null;

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(props.id);
        copied.value = true;
        failed.value = false;
    } catch {
        copied.value = false;
        failed.value = true;
    }

    if (resetTimer !== null) {
        clearTimeout(resetTimer);
    }
    resetTimer = setTimeout(() => {
        copied.value = false;
        failed.value = false;
    }, 2000);
}

onBeforeUnmount(() => {
    if (resetTimer !== null) {
        clearTimeout(resetTimer);
    }
});
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-1">
        <code class="truncate font-mono text-xs text-surface-500 dark:text-surface-400" :title="id">&hellip;{{ id.slice(-8) }}</code>
        <button
            type="button"
            :aria-label="copied ? 'Copied' : 'Copy note ID'"
            title="Copy note ID"
            class="flex shrink-0 cursor-pointer items-center gap-1 rounded-md px-1 py-0.5 text-surface-400 hover:bg-surface-100 hover:text-surface-600 dark:text-surface-500 dark:hover:bg-surface-700/40 dark:hover:text-surface-300"
            @click.stop.prevent="copy"
        >
            <YIcon v-if="!copied && !failed" name="copy" />
            <span v-else-if="copied" class="flex items-center gap-1 text-xs font-medium">
                <YIcon name="check" />
                Copied
            </span>
            <span v-else class="text-xs font-medium">Copy failed</span>
        </button>
    </span>
</template>
