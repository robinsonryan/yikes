<script setup lang="ts">
import { ref } from "vue";
import type { RoleInfo } from "../types";
import YIcon from "../ui/YIcon.vue";

/**
 * Shared role→capabilities popover for the tester landing page (one instance
 * serves every credential row). Desktop is hover-driven: the trigger calls
 * `showFor` on mouseenter and `scheduleHide` on mouseleave; the popover body
 * cancels the pending hide while hovered (hover-intent grace period).
 * `toggleFor` covers tap/keyboard: a second activation of the same trigger
 * closes; a different trigger moves the popover to the new anchor.
 */
defineProps<{
    /** Link to the host app's full roles reference page, if it has one. */
    referenceUrl: string | null;
}>();

const HIDE_GRACE_MS = 200;

const role = ref<RoleInfo | null>(null);
const isOpen = ref(false);
const position = ref({ left: 0, top: 0 });

let hideTimer: ReturnType<typeof setTimeout> | null = null;

function cancelHide(): void {
    if (hideTimer !== null) {
        clearTimeout(hideTimer);
        hideTimer = null;
    }
}

function scheduleHide(): void {
    cancelHide();
    hideTimer = setTimeout(() => {
        isOpen.value = false;
    }, HIDE_GRACE_MS);
}

function anchorTo(event: Event): void {
    const target = event.currentTarget as HTMLElement | null;
    const rect = target?.getBoundingClientRect();

    if (!rect) {
        return;
    }

    const width = 320;
    position.value = {
        left: Math.min(Math.max(8, rect.left), window.innerWidth - width - 8),
        top: rect.bottom + 6,
    };
}

function showFor(event: Event, next: RoleInfo): void {
    cancelHide();
    role.value = next;
    anchorTo(event);
    isOpen.value = true;
}

function toggleFor(event: Event, next: RoleInfo): void {
    cancelHide();

    if (isOpen.value && role.value?.key === next.key) {
        isOpen.value = false;
        return;
    }

    showFor(event, next);
}

defineExpose({ showFor, toggleFor, scheduleHide, cancelHide });
</script>

<template>
    <div
        v-if="isOpen && role"
        class="fixed z-[999950] w-80 rounded-md border border-surface-200 bg-surface-0 p-3 shadow-lg dark:border-surface-700 dark:bg-surface-800"
        :style="{ left: `${position.left}px`, top: `${position.top}px` }"
        @mouseenter="cancelHide"
        @mouseleave="scheduleHide"
    >
        <div class="space-y-2">
            <div class="text-xs font-semibold tracking-wide text-surface-500 uppercase dark:text-surface-400">
                {{ role.name }}
            </div>
            <p v-if="role.summary" class="text-xs text-surface-600 dark:text-surface-400">
                {{ role.summary }}
            </p>
            <ul class="space-y-1">
                <li
                    v-for="capability in role.can"
                    :key="capability"
                    class="flex items-start gap-2 text-sm text-surface-700 dark:text-surface-300"
                >
                    <YIcon name="check" class="mt-0.5 text-success-600 dark:text-success-400" />
                    <span class="min-w-0">{{ capability }}</span>
                </li>
            </ul>
            <a
                v-if="referenceUrl"
                :href="referenceUrl"
                class="block text-xs text-primary-600 hover:underline dark:text-primary-400"
            >
                Full roles &amp; permissions matrix →
            </a>
        </div>
    </div>
</template>
