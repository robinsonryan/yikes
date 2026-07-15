<script setup lang="ts">
import { inject, nextTick, ref } from "vue";
import { api, noteUrl } from "../api";
import { bootstrap } from "../bootstrap";
import { pickElement } from "../composables/useElementPicker";
import { fitUnderCap } from "../utils/downscale";
import { useDraggableFab } from "../composables/useDraggableFab";
import { usePendingScreenshots } from "../composables/usePendingScreenshots";
import type { YikesElementContext } from "../types";
import YIcon from "../ui/YIcon.vue";
import { useYikesToast } from "../ui/toast";
import YikesDialog from "./YikesDialog.vue";

/**
 * The floating pill: drag grip, open-index, element pick, screenshot snap,
 * and note capture. Mounted once per page by the island entry.
 */
const shadowHost = inject<HTMLElement>("yikes:shadowHost")!;
const overlayHost = inject<HTMLElement>("yikes:container")!;

const { add: toast } = useYikesToast();
const { pendingIds, add } = usePendingScreenshots();

// Hub mode: the local index UI is disabled (triage lives on the hub), so
// the FAB drops its index link.
const { hub: hubMode, maxScreenshotBytes } = bootstrap();

const showDialog = ref(false);
const isCapturing = ref(false);
const isPicking = ref(false);
const pickedElement = ref<YikesElementContext | null>(null);

// The pill inevitably covers SOMETHING wherever it sits — let the user drag it
// out of the way (grip handle only; the action buttons stay taps). Position
// persists across pages/sessions.
const fabEl = ref<HTMLElement | null>(null);
const { style: fabStyle, onHandlePointerDown } = useDraggableFab(fabEl);

async function uploadScreenshot(blob: Blob): Promise<void> {
    // Full-page PNGs can blow past the upload cap (the hub hard-rejects
    // over 5 MB) — downscale in the browser, the only layer with a canvas.
    const capped = await fitUnderCap(blob, maxScreenshotBytes);

    const formData = new FormData();
    formData.append("screenshot", capped, "screenshot.png");

    const response = await api.postForm<{ id: string }>(noteUrl("/screenshots"), formData);
    add(response.id);
}

async function snapScreenshot(): Promise<void> {
    if (isCapturing.value) {
        return;
    }
    isCapturing.value = true;
    // Let the v-show hide the FAB before the DOM is captured.
    await nextTick();

    try {
        // Dynamic import so the capture library is code-split out of the
        // main bundle and only loads on first snap.
        const { snapdom } = await import("@zumer/snapdom");
        const capture = await snapdom(document.body);
        const blob = await capture.toBlob({ type: "png" });

        await uploadScreenshot(blob);

        toast({
            severity: "success",
            summary: "Screenshot captured",
            detail: "It will attach to your next yikes note.",
        });
    } catch {
        toast({
            severity: "error",
            summary: "Screenshot failed",
            detail: "Could not capture or upload the screenshot.",
            life: 5000,
        });
    } finally {
        isCapturing.value = false;
    }
}

async function startElementPick(): Promise<void> {
    if (isPicking.value) {
        return;
    }
    isPicking.value = true;

    try {
        const picked = await pickElement(overlayHost, shadowHost);

        if (!picked) {
            return;
        }

        pickedElement.value = picked.context;

        // Best-effort element screenshot — the note is still filed if the
        // capture fails (e.g. cross-origin images inside the element).
        try {
            const { snapdom } = await import("@zumer/snapdom");
            const capture = await snapdom(picked.element);
            await uploadScreenshot(await capture.toBlob({ type: "png" }));
        } catch {
            // Ignore — selector + text still identify the element.
        }

        showDialog.value = true;
    } finally {
        isPicking.value = false;
    }
}

function openNoteDialog(): void {
    pickedElement.value = null;
    showDialog.value = true;
}
</script>

<template>
    <div>
        <!-- Hidden (v-show) while capturing so the FAB never appears in its
             own screenshots, while picking so it can't be picked, and while
             its own dialog is open so it can't cover the compose form. -->
        <div
            ref="fabEl"
            v-show="!isCapturing && !isPicking && !showDialog"
            :style="fabStyle"
            class="fixed right-4 bottom-4 z-[999900] flex flex-col items-center gap-1.5 rounded-full border border-surface-200 bg-surface-0/90 p-1.5 shadow-lg backdrop-blur-sm dark:border-surface-700 dark:bg-surface-800/90 print:hidden"
        >
            <button
                type="button"
                class="flex h-5 w-8 cursor-grab touch-none items-center justify-center text-surface-400 hover:text-surface-600 active:cursor-grabbing dark:text-surface-500 dark:hover:text-surface-300"
                aria-label="Drag to move the yikes buttons"
                @pointerdown="onHandlePointerDown"
            >
                <YIcon name="grip" />
            </button>
            <a
                v-if="!hubMode"
                :href="noteUrl()"
                class="flex size-9 items-center justify-center rounded-full text-surface-500 hover:bg-surface-100 dark:text-surface-400 dark:hover:bg-surface-700/60"
                aria-label="Open the yikes notes index"
            >
                <YIcon name="home" />
            </a>
            <button
                type="button"
                class="flex size-9 cursor-pointer items-center justify-center rounded-full text-surface-500 hover:bg-surface-100 dark:text-surface-400 dark:hover:bg-surface-700/60"
                aria-label="Pick a page element for a yikes note"
                @click="startElementPick"
            >
                <YIcon name="crosshair" />
            </button>
            <div class="relative">
                <button
                    type="button"
                    class="flex size-9 cursor-pointer items-center justify-center rounded-full bg-surface-700 text-white hover:bg-surface-800 dark:bg-surface-600 dark:hover:bg-surface-500"
                    aria-label="Snap a screenshot for a yikes note"
                    @click="snapScreenshot"
                >
                    <YIcon name="camera" />
                </button>
                <span
                    v-if="pendingIds.length"
                    class="pointer-events-none absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-primary-600 px-1 text-xs font-semibold text-white dark:bg-primary-400 dark:text-surface-900"
                    aria-hidden="true"
                >
                    {{ pendingIds.length }}
                </span>
            </div>
            <button
                type="button"
                class="flex size-9 cursor-pointer items-center justify-center rounded-full bg-primary-600 text-white hover:bg-primary-700 dark:bg-primary-400 dark:text-surface-950 dark:hover:bg-primary-300"
                aria-label="Write a yikes note about this page"
                @click="openNoteDialog"
            >
                <YIcon name="alert-circle" />
            </button>
        </div>

        <YikesDialog v-model:visible="showDialog" :element="pickedElement" />
    </div>
</template>
