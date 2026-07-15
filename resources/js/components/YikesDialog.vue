<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { api, noteUrl } from "../api";
import { bootstrap } from "../bootstrap";
import { captureContext, captureState } from "../context";
import { usePendingScreenshots } from "../composables/usePendingScreenshots";
import { useYikesForm } from "../composables/useYikesForm";
import type { YikesContext, YikesElementContext, YikesNoteType } from "../types";
import YButton from "../ui/YButton.vue";
import YCheckbox from "../ui/YCheckbox.vue";
import YDialog from "../ui/YDialog.vue";
import YIcon from "../ui/YIcon.vue";
import YInput from "../ui/YInput.vue";
import YMessage from "../ui/YMessage.vue";
import YSelect from "../ui/YSelect.vue";
import YTextarea from "../ui/YTextarea.vue";
import { useYikesToast } from "../ui/toast";

const props = defineProps<{
    /** Element context from the picker — the note is about this element. */
    element?: YikesElementContext | null;
}>();

const visible = defineModel<boolean>("visible", { default: false });

const { add: toast } = useYikesToast();
const { pendingIds, remove, removeMany } = usePendingScreenshots();

const typeOptions: { label: string; value: YikesNoteType }[] = [
    { label: "Bug", value: "bug" },
    { label: "Layout", value: "layout" },
    { label: "Idea", value: "idea" },
    { label: "Refactor", value: "refactor" },
];

const form = useYikesForm<{ title: string; type: YikesNoteType; body: string }>({
    title: "",
    type: "bug",
    body: "",
});

/** Per-pending-screenshot include flag (default: included). */
const included = reactive<Record<string, boolean>>({});
const capturedContext = ref<YikesContext | null>(null);
const capturedState = ref<string | null>(null);
const showContext = ref(false);
const deletingId = ref<string | null>(null);

/**
 * Fast-track: save the note pre-approved, skipping the triage step.
 * Hidden in hub mode — the hub owns triage, and its ingest API creates
 * every note as `new`.
 */
const approveNow = ref(false);
const { hub: hubMode } = bootstrap();

// Context is captured at dialog-open time so it reflects the page the user
// was looking at, not the moment they hit Save.
watch(visible, (open) => {
    if (open) {
        capturedContext.value = { ...captureContext(), element: props.element ?? null };
        capturedState.value = captureState();
        showContext.value = false;
        approveNow.value = false;
        for (const id of pendingIds.value) {
            if (!(id in included)) {
                included[id] = true;
            }
        }
    }
});

const includedIds = computed(() => pendingIds.value.filter((id) => included[id] !== false));

const contextSummary = computed(() => {
    const context = capturedContext.value;
    if (!context) return [];
    return [
        { label: "Page", value: context.page ?? context.title ?? "—" },
        { label: "Route", value: context.route ?? "—" },
        { label: "URL", value: context.url ?? "—" },
        { label: "Account", value: context.account?.name ?? "—" },
        {
            label: "Mode",
            value: context.dark_mode == null ? "—" : context.dark_mode ? "dark" : "light",
        },
        {
            label: "Viewport",
            value: context.viewport ? `${context.viewport.width}×${context.viewport.height}` : "—",
        },
        {
            label: "State",
            value: capturedState.value ? `${Math.ceil(capturedState.value.length / 1024)}KB` : "—",
        },
    ];
});

function pendingUrl(id: string): string {
    return noteUrl(`/screenshots/pending/${id}`);
}

function close(): void {
    visible.value = false;
}

async function deletePending(id: string): Promise<void> {
    deletingId.value = id;
    try {
        await api.delete(pendingUrl(id));
        remove(id);
        delete included[id];
    } catch {
        toast({
            severity: "error",
            summary: "Error",
            detail: "Could not delete the pending screenshot.",
            life: 5000,
        });
    } finally {
        deletingId.value = null;
    }
}

function submit(): void {
    const attached = includedIds.value;

    void form
        .transform((data) => ({
            ...data,
            title: data.title.trim() || null,
            context: capturedContext.value ?? captureContext(),
            state: capturedState.value,
            screenshots: attached,
            status: approveNow.value ? "approved" : "new",
        }))
        .submit("post", noteUrl("/notes"), {
            onSuccess: () => {
                removeMany(attached);
                for (const id of attached) {
                    delete included[id];
                }
                form.reset();
                form.clearErrors();
                close();
                toast({
                    severity: "success",
                    summary: "Yikes noted",
                    detail: approveNow.value
                        ? "Saved pre-approved — it's already in Claude's queue."
                        : "Your note and its page context were saved.",
                });
            },
        });
}
</script>

<template>
    <YDialog v-model:visible="visible" header="Yikes — capture a note">
        <form class="space-y-4" @submit.prevent="submit">
            <!-- Picked element chip -->
            <div
                v-if="capturedContext?.element"
                class="flex items-start gap-2 rounded-md border border-primary-200 bg-primary-50 p-2.5 text-xs dark:border-primary-800 dark:bg-primary-950/40"
            >
                <YIcon name="crosshair" class="mt-0.5 text-primary-600 dark:text-primary-400" />
                <div class="min-w-0">
                    <p class="font-mono text-primary-700 dark:text-primary-300 break-all">
                        {{ capturedContext.element.selector }}
                    </p>
                    <p
                        v-if="capturedContext.element.text"
                        class="mt-0.5 line-clamp-2 text-surface-600 dark:text-surface-400"
                    >
                        “{{ capturedContext.element.text }}”
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[10rem_1fr]">
                <div class="space-y-1">
                    <label for="yikes-type" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        Type
                    </label>
                    <YSelect id="yikes-type" v-model="form.data.type" :options="typeOptions" />
                </div>
                <div class="space-y-1">
                    <label for="yikes-title" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        Title <span class="text-surface-400 dark:text-surface-500">(optional)</span>
                    </label>
                    <YInput
                        id="yikes-title"
                        v-model="form.data.title"
                        placeholder="Short summary"
                        :invalid="!!form.errors.title"
                    />
                    <YMessage v-if="form.errors.title" severity="error" size="sm">
                        {{ form.errors.title }}
                    </YMessage>
                </div>
            </div>

            <div class="space-y-1">
                <label for="yikes-body" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                    What needs fixing?
                </label>
                <YTextarea
                    id="yikes-body"
                    v-model="form.data.body"
                    :rows="5"
                    autofocus
                    placeholder="Describe the bug, layout issue, idea, or refactor…"
                    :invalid="!!form.errors.body"
                />
                <YMessage v-if="form.errors.body" severity="error" size="sm">
                    {{ form.errors.body }}
                </YMessage>
            </div>

            <!-- Pending screenshots -->
            <div v-if="pendingIds.length" class="space-y-2">
                <p class="text-sm font-medium text-surface-700 dark:text-surface-300">
                    Screenshots ({{ includedIds.length }} of {{ pendingIds.length }} attached)
                </p>
                <div class="flex gap-3 overflow-x-auto pb-1">
                    <div
                        v-for="id in pendingIds"
                        :key="id"
                        class="w-28 shrink-0 rounded-md border border-surface-200 p-1.5 dark:border-surface-700"
                    >
                        <a :href="pendingUrl(id)" target="_blank" rel="noopener">
                            <img
                                :src="pendingUrl(id)"
                                alt="Pending screenshot"
                                class="h-16 w-full rounded-sm bg-surface-100 object-cover object-top dark:bg-surface-900"
                            />
                        </a>
                        <div class="mt-1.5 flex items-center justify-between">
                            <YCheckbox v-model="included[id]" :input-id="`yikes-include-${id}`" aria-label="Include screenshot" />
                            <YButton
                                icon="trash"
                                text
                                variant="danger"
                                size="sm"
                                :loading="deletingId === id"
                                aria-label="Delete screenshot"
                                @click="deletePending(id)"
                            />
                        </div>
                    </div>
                </div>
            </div>

            <!-- Collapsed context summary -->
            <div class="rounded-md border border-surface-200 dark:border-surface-700">
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center justify-between rounded-md px-3 py-2 text-left text-sm text-surface-600 hover:bg-surface-50 dark:text-surface-300 dark:hover:bg-surface-700/40"
                    :aria-expanded="showContext"
                    @click="showContext = !showContext"
                >
                    <span>Context that will be saved</span>
                    <YIcon :name="showContext ? 'chevron-up' : 'chevron-down'" />
                </button>
                <dl v-show="showContext" class="space-y-1 border-t border-surface-200 px-3 py-2 text-xs dark:border-surface-700">
                    <div v-for="item in contextSummary" :key="item.label" class="flex min-w-0 gap-2">
                        <dt class="w-24 shrink-0 text-surface-500 dark:text-surface-400">
                            {{ item.label }}
                        </dt>
                        <dd class="min-w-0 truncate text-surface-700 dark:text-surface-300" :title="item.value">
                            {{ item.value }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                <!-- Fast-track toggle: skip triage, save straight to approved. -->
                <button
                    v-if="!hubMode"
                    type="button"
                    :aria-pressed="approveNow"
                    class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors"
                    :class="
                        approveNow
                            ? 'border-primary-300 bg-primary-50 text-primary-700 dark:border-primary-700 dark:bg-primary-950 dark:text-primary-300'
                            : 'border-surface-200 text-surface-500 hover:bg-surface-50 dark:border-surface-700 dark:text-surface-400 dark:hover:bg-surface-700/40'
                    "
                    @click="approveNow = !approveNow"
                >
                    <YIcon name="bolt" class="transition-transform" :class="approveNow ? 'scale-110 -rotate-12' : ''" />
                    {{ approveNow ? "Fast-tracked" : "Fast-track" }}
                </button>
                <!-- ml-auto keeps the actions right-aligned when the
                     fast-track toggle is hidden (hub mode). -->
                <div class="ml-auto flex gap-2">
                    <YButton variant="secondary" label="Cancel" @click="close" />
                    <YButton
                        type="submit"
                        :label="approveNow ? 'Save & approve' : 'Save note'"
                        :icon="approveNow ? 'bolt' : undefined"
                        :loading="form.processing"
                    />
                </div>
            </div>
        </form>
    </YDialog>
</template>
