<script setup lang="ts">
import { ref, watch } from "vue";
import { noteUrl } from "../api";
import { useYikesForm } from "../composables/useYikesForm";
import type { YikesNoteType } from "../types";
import YButton from "../ui/YButton.vue";
import YDialog from "../ui/YDialog.vue";
import YIcon from "../ui/YIcon.vue";
import YInput from "../ui/YInput.vue";
import YMessage from "../ui/YMessage.vue";
import YSelect from "../ui/YSelect.vue";
import YTextarea from "../ui/YTextarea.vue";
import { useYikesToast } from "../ui/toast";

/**
 * Create a generic yikes note from the index page — one that isn't tied to
 * the page it was captured on. The context is whatever the user explicitly
 * enters (URL and page/area, both optional); nothing is auto-captured.
 */
const visible = defineModel<boolean>("visible", { default: false });

const emit = defineEmits<{ saved: [] }>();

const { add: toast } = useYikesToast();

const typeOptions: { label: string; value: YikesNoteType }[] = [
    { label: "Bug", value: "bug" },
    { label: "Layout", value: "layout" },
    { label: "Idea", value: "idea" },
    { label: "Refactor", value: "refactor" },
];

const form = useYikesForm<{
    title: string;
    type: YikesNoteType;
    body: string;
    contextUrl: string;
    contextPage: string;
}>({
    title: "",
    type: "idea",
    body: "",
    contextUrl: "",
    contextPage: "",
});

/** Fast-track: save the note pre-approved, skipping the triage step. */
const approveNow = ref(false);

watch(visible, (open) => {
    if (open) {
        form.reset();
        form.clearErrors();
        approveNow.value = false;
    }
});

function close(): void {
    visible.value = false;
}

function submit(): void {
    void form
        .transform((data) => ({
            title: data.title.trim() || null,
            type: data.type,
            body: data.body,
            context: {
                url: data.contextUrl.trim() || null,
                route: null,
                page: data.contextPage.trim() || null,
                account: null,
                department: null,
                dark_mode: null,
                viewport: null,
                user_agent: null,
            },
            state: null,
            screenshots: [],
            status: approveNow.value ? "approved" : "new",
        }))
        .submit("post", noteUrl("/notes"), {
            onSuccess: () => {
                form.reset();
                form.clearErrors();
                close();
                emit("saved");
                toast({
                    severity: "success",
                    summary: "Yikes noted",
                    detail: approveNow.value
                        ? "Saved pre-approved — it's already in Claude's queue."
                        : "Your note was saved.",
                });
            },
        });
}
</script>

<template>
    <YDialog v-model:visible="visible" header="New yikes note">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-[10rem_1fr]">
                <div class="space-y-1">
                    <label for="yikes-create-type" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        Type
                    </label>
                    <YSelect id="yikes-create-type" v-model="form.data.type" :options="typeOptions" />
                </div>
                <div class="space-y-1">
                    <label for="yikes-create-title" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        Title <span class="text-surface-400 dark:text-surface-500">(optional)</span>
                    </label>
                    <YInput
                        id="yikes-create-title"
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
                <label for="yikes-create-body" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                    What needs doing?
                </label>
                <YTextarea
                    id="yikes-create-body"
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

            <!-- Explicit context — everything optional, nothing auto-captured -->
            <fieldset class="space-y-3 rounded-md border border-surface-200 p-3 dark:border-surface-700">
                <legend class="px-1 text-xs text-surface-500 dark:text-surface-400">Context (optional)</legend>
                <div class="space-y-1">
                    <label for="yikes-create-page" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        Page or area
                    </label>
                    <YInput id="yikes-create-page" v-model="form.data.contextPage" placeholder="e.g. checkout, or “site-wide”" />
                </div>
                <div class="space-y-1">
                    <label for="yikes-create-url" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        URL
                    </label>
                    <YInput id="yikes-create-url" v-model="form.data.contextUrl" placeholder="https://…" />
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center justify-between gap-2 pt-2">
                <!-- Fast-track toggle: skip triage, save straight to approved. -->
                <button
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
                <div class="flex gap-2">
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
