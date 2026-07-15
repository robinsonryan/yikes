<script setup lang="ts">
import { computed, ref, watchEffect } from "vue";
import { api, noteUrl } from "../api";
import { useYikesForm } from "../composables/useYikesForm";
import type { YikesNote, YikesNoteStatus } from "../types";
import YikesCreateDialog from "../components/YikesCreateDialog.vue";
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
    notes: YikesNote[];
    filters?: { status?: YikesNoteStatus | null };
}>();

watchEffect(() => {
    document.title = "Yikes";
});

const { add: toast } = useYikesToast();

// Local working copy — mutations refetch the same route as JSON and swap it.
const notes = ref<YikesNote[]>(props.notes);

async function refresh(): Promise<void> {
    try {
        const fresh = await api.get<{ notes: YikesNote[] }>(window.location.href);
        notes.value = fresh.notes;
    } catch {
        toast({ severity: "error", summary: "Could not refresh the notes list." });
    }
}

const statusOptions: { label: string; value: YikesNoteStatus }[] = [
    { label: "New", value: "new" },
    { label: "On hold", value: "on-hold" },
    { label: "Approved", value: "approved" },
    { label: "Done", value: "done" },
    { label: "Ignored", value: "ignored" },
];

const filterOptions = [{ label: "All", value: "all" }, ...statusOptions];

const statusFilter = ref<YikesNoteStatus | "all">(props.filters?.status ?? "all");

// Done notes are hidden by default; "Include completed" (or explicitly
// selecting the Done status) brings them back.
const includeCompleted = ref(false);

const doneCount = computed(() => notes.value.filter((note) => note.status === "done").length);

const filteredNotes = computed(() => {
    let list =
        statusFilter.value === "all"
            ? notes.value
            : notes.value.filter((note) => note.status === statusFilter.value);

    if (statusFilter.value === "all" && !includeCompleted.value) {
        list = list.filter((note) => note.status !== "done");
    }

    // Newest first, regardless of server ordering.
    return [...list].sort((a, b) => b.created_at.localeCompare(a.created_at));
});

const typeLabels: Record<YikesNote["type"], string> = {
    bug: "Bug",
    layout: "Layout",
    idea: "Idea",
    refactor: "Refactor",
};

const updatingStatusId = ref<string | null>(null);

async function updateStatus(note: YikesNote, status: YikesNoteStatus): Promise<void> {
    if (status === note.status) return;
    updatingStatusId.value = note.id;
    try {
        await api.patch(noteUrl(`/notes/${note.id}/status`), { status });
        await refresh();
    } catch {
        toast({ severity: "error", summary: "Could not update the note status." });
    } finally {
        updatingStatusId.value = null;
    }
}

// Edit state — only the note's text is editable; the captured context,
// state snapshot, and screenshots are the permanent record.
const showEditModal = ref(false);
const editing = ref<YikesNote | null>(null);
const editForm = useYikesForm<{ title: string; body: string }>({ title: "", body: "" });

function openEdit(note: YikesNote): void {
    editing.value = note;
    editForm.data.title = note.title ?? "";
    editForm.data.body = note.body;
    editForm.clearErrors();
    showEditModal.value = true;
}

function closeEditModal(): void {
    showEditModal.value = false;
    editing.value = null;
}

function submitEdit(): void {
    if (!editing.value) return;
    void editForm
        .transform((data) => ({ ...data, title: data.title.trim() || null }))
        .submit("patch", noteUrl(`/notes/${editing.value.id}`), {
            onSuccess: () => {
                closeEditModal();
                void refresh();
            },
        });
}

// Generic note creation (not tied to a captured page context).
const showCreateModal = ref(false);

// "Clear completed" confirmation state.
const showClearCompletedModal = ref(false);
const isClearingCompleted = ref(false);

async function confirmClearCompleted(): Promise<void> {
    isClearingCompleted.value = true;
    try {
        const response = await api.delete<{ message: string }>(noteUrl("/notes/completed"));
        toast({ severity: "success", summary: response.message });
        await refresh();
    } catch {
        toast({ severity: "error", summary: "Could not clear the completed notes." });
    } finally {
        isClearingCompleted.value = false;
        showClearCompletedModal.value = false;
    }
}

// Delete confirmation state.
const showDeleteModal = ref(false);
const deleting = ref<YikesNote | null>(null);
const isDeleting = ref(false);

function openDelete(note: YikesNote): void {
    deleting.value = note;
    showDeleteModal.value = true;
}

function closeDeleteModal(): void {
    showDeleteModal.value = false;
    deleting.value = null;
}

async function confirmDelete(): Promise<void> {
    if (!deleting.value) return;
    isDeleting.value = true;
    try {
        await api.delete(noteUrl(`/notes/${deleting.value.id}`));
        await refresh();
    } catch {
        toast({ severity: "error", summary: "Could not delete the note." });
    } finally {
        isDeleting.value = false;
        closeDeleteModal();
    }
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
    });
}

function contextLine(note: YikesNote): string {
    // Generic (manually created) notes may carry only partial context.
    const parts = [
        note.context.page ?? note.context.title ?? null,
        note.context.route ?? null,
        note.context.account?.name ?? null,
        typeof note.context.dark_mode === "boolean" ? (note.context.dark_mode ? "dark" : "light") : null,
        note.context.viewport ? `${note.context.viewport.width}×${note.context.viewport.height}` : null,
    ];
    return parts.filter((part): part is string => part !== null).join(" · ");
}
</script>

<template>
    <div class="min-h-screen bg-surface-100 font-sans text-surface-900 dark:bg-surface-900 dark:text-surface-100">
        <div class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-6">
            <!-- Page header -->
            <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-surface-900 dark:text-surface-0">Yikes</h1>
                    <p class="mt-1 text-sm text-surface-500">
                        Dev/QC notes captured in-app — approve the ones Claude should work
                    </p>
                </div>
                <YButton label="Add note" icon="plus" class="self-start sm:self-auto" @click="showCreateModal = true" />
            </div>

            <!-- Toolbar: status filter, include-completed toggle, clear completed -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <YSelect
                    v-model="statusFilter"
                    :options="filterOptions"
                    class="w-full sm:w-48"
                    aria-label="Filter by status"
                />
                <div v-if="statusFilter === 'all'" class="flex items-center gap-2">
                    <YCheckbox v-model="includeCompleted" input-id="yikes-include-completed" />
                    <label
                        for="yikes-include-completed"
                        class="cursor-pointer text-sm text-surface-700 dark:text-surface-300"
                    >
                        Include completed ({{ doneCount }})
                    </label>
                </div>
                <YButton
                    v-if="doneCount > 0"
                    label="Clear completed"
                    icon="eraser"
                    variant="secondary"
                    text
                    size="sm"
                    class="self-start sm:ml-auto sm:self-auto"
                    @click="showClearCompletedModal = true"
                />
            </div>

            <!-- Empty state -->
            <div v-if="filteredNotes.length === 0" class="py-12 text-center">
                <div class="text-surface-400 dark:text-surface-500">
                    <YIcon name="alert-circle" class="mb-4 text-4xl" />
                    <p class="text-lg">
                        {{ statusFilter === "all" ? "No yikes notes yet" : "No notes with this status" }}
                    </p>
                    <p class="mt-1 text-sm">Use the floating button on any page to capture one.</p>
                </div>
            </div>

            <!-- Note cards -->
            <div v-else class="space-y-3">
                <div
                    v-for="note in filteredNotes"
                    :key="note.id"
                    class="rounded-md border border-surface-200 bg-surface-0 p-4 shadow-sm dark:border-surface-700 dark:bg-surface-800"
                >
                    <!-- Header row: type, created, status, edit, delete -->
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <span
                            class="rounded-full bg-categorical-100 px-2 py-0.5 text-xs font-medium text-categorical-700 dark:bg-categorical-900/40 dark:text-categorical-300"
                        >
                            {{ typeLabels[note.type] }}
                        </span>
                        <span class="text-xs text-surface-500 dark:text-surface-400">
                            {{ formatDate(note.created_at) }} · {{ note.created_by.name }}
                        </span>
                        <div class="ml-auto flex items-center gap-2">
                            <YSelect
                                :model-value="note.status"
                                :options="statusOptions"
                                size="sm"
                                class="w-32"
                                :disabled="updatingStatusId === note.id"
                                aria-label="Note status"
                                @update:model-value="(status) => updateStatus(note, status as YikesNoteStatus)"
                            />
                            <YButton icon="pencil" text variant="secondary" size="sm" aria-label="Edit note" @click="openEdit(note)" />
                            <YButton icon="trash" text variant="danger" size="sm" aria-label="Delete note" @click="openDelete(note)" />
                        </div>
                    </div>

                    <!-- Title + body -->
                    <div class="mt-3 min-w-0">
                        <p
                            v-if="note.title"
                            class="truncate font-medium text-surface-900 dark:text-surface-0"
                            :title="note.title"
                        >
                            {{ note.title }}
                        </p>
                        <p class="mt-1 line-clamp-3 text-sm whitespace-pre-line text-surface-700 dark:text-surface-300">
                            {{ note.body }}
                        </p>
                    </div>

                    <!-- Picked element -->
                    <p
                        v-if="note.context.element"
                        class="mt-2 truncate font-mono text-xs text-primary-700 dark:text-primary-300"
                        :title="note.context.element.selector"
                    >
                        <YIcon name="crosshair" /> {{ note.context.element.selector }}
                    </p>

                    <!-- Screenshots -->
                    <div v-if="note.screenshots.length" class="mt-3 flex gap-2 overflow-x-auto pb-1">
                        <a
                            v-for="shot in note.screenshots"
                            :key="shot.file"
                            :href="shot.url"
                            target="_blank"
                            rel="noopener"
                            class="shrink-0"
                        >
                            <img
                                :src="shot.url"
                                alt="Note screenshot"
                                class="h-16 w-24 rounded-sm border border-surface-200 bg-surface-100 object-cover object-top dark:border-surface-700 dark:bg-surface-900"
                            />
                        </a>
                    </div>

                    <!-- Context line (generic notes may have none) -->
                    <div
                        v-if="contextLine(note) || note.context.url"
                        class="mt-3 flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-xs text-surface-500 dark:text-surface-400"
                    >
                        <span class="min-w-0 truncate" :title="contextLine(note)">
                            {{ contextLine(note) }}
                        </span>
                        <a
                            v-if="note.context.url"
                            :href="note.context.url"
                            target="_blank"
                            rel="noopener"
                            class="shrink-0 text-primary-600 hover:underline dark:text-primary-400"
                        >
                            Open page <YIcon name="external-link" />
                        </a>
                    </div>

                    <!-- Resolution (set by the process-yikes skill) -->
                    <div
                        v-if="note.resolution"
                        class="mt-3 border-t border-surface-200 pt-2 text-xs text-surface-600 dark:border-surface-700 dark:text-surface-300"
                    >
                        <span class="font-medium">Resolved:</span>
                        {{ note.resolution.note ?? "—" }}
                        <code
                            v-if="note.resolution.commit"
                            class="ml-1 rounded-sm bg-surface-100 px-1 py-0.5 font-mono dark:bg-surface-900"
                        >
                            {{ note.resolution.commit.slice(0, 8) }}
                        </code>
                    </div>
                </div>
            </div>
        </div>

        <!-- Generic note creation — context entered explicitly, not captured -->
        <YikesCreateDialog v-model:visible="showCreateModal" @saved="refresh" />

        <!-- Clear completed confirmation modal -->
        <YDialog v-model:visible="showClearCompletedModal" header="Clear completed" width="sm:max-w-md">
            <div class="space-y-4">
                <p class="text-sm text-surface-700 dark:text-surface-300">
                    Remove all {{ doneCount }} completed {{ doneCount === 1 ? "note" : "notes" }} from the system?
                    Their context, state snapshots, and screenshots are removed too.
                </p>

                <div class="flex justify-end gap-2 pt-2">
                    <YButton variant="secondary" label="Cancel" @click="showClearCompletedModal = false" />
                    <YButton
                        variant="danger"
                        label="Clear completed"
                        icon="eraser"
                        :loading="isClearingCompleted"
                        @click="confirmClearCompleted"
                    />
                </div>
            </div>
        </YDialog>

        <!-- Edit modal — title + body only -->
        <YDialog v-model:visible="showEditModal" header="Edit note">
            <form class="space-y-4" @submit.prevent="submitEdit">
                <div class="space-y-1">
                    <label for="yikes-edit-title" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        Title <span class="text-surface-400 dark:text-surface-500">(optional)</span>
                    </label>
                    <YInput
                        id="yikes-edit-title"
                        v-model="editForm.data.title"
                        placeholder="Short summary"
                        :invalid="!!editForm.errors.title"
                    />
                    <YMessage v-if="editForm.errors.title" severity="error" size="sm">
                        {{ editForm.errors.title }}
                    </YMessage>
                </div>

                <div class="space-y-1">
                    <label for="yikes-edit-body" class="block text-sm font-medium text-surface-700 dark:text-surface-300">
                        Note
                    </label>
                    <YTextarea id="yikes-edit-body" v-model="editForm.data.body" :rows="6" :invalid="!!editForm.errors.body" />
                    <YMessage v-if="editForm.errors.body" severity="error" size="sm">
                        {{ editForm.errors.body }}
                    </YMessage>
                </div>

                <div class="flex justify-end gap-2 pt-2">
                    <YButton variant="secondary" label="Cancel" @click="closeEditModal" />
                    <YButton type="submit" label="Save changes" :loading="editForm.processing" />
                </div>
            </form>
        </YDialog>

        <!-- Delete confirmation modal -->
        <YDialog v-model:visible="showDeleteModal" header="Delete note" width="sm:max-w-md">
            <div class="space-y-4">
                <p class="text-sm text-surface-700 dark:text-surface-300">
                    Delete
                    <span class="font-semibold">{{ deleting?.title || typeLabels[deleting?.type ?? "bug"] }}</span
                    >? Its context, state snapshot, and screenshots are removed too.
                </p>

                <div class="flex justify-end gap-2 pt-2">
                    <YButton variant="secondary" label="Cancel" @click="closeDeleteModal" />
                    <YButton variant="danger" label="Delete" icon="trash" :loading="isDeleting" @click="confirmDelete" />
                </div>
            </div>
        </YDialog>
    </div>
</template>
