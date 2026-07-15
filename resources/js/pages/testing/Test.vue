<script setup lang="ts">
import { computed, ref } from "vue";
import { api, testingUrl } from "../../api";
import ChecklistShell from "../../components/ChecklistShell.vue";
import { useYikesForm } from "../../composables/useYikesForm";
import { checklistTextHtml } from "../../utils/checklistText";
import type { StepResult } from "../../types";
import YButton from "../../ui/YButton.vue";
import YDialog from "../../ui/YDialog.vue";
import YMessage from "../../ui/YMessage.vue";
import YTextarea from "../../ui/YTextarea.vue";
import { useYikesToast } from "../../ui/toast";

const props = defineProps<{
    tester: { slug: string; name: string };
    suite: { slug: string; title: string };
    test: { slug: string; title: string; goal: string | null; steps: string[] };
    results: Record<string, StepResult>;
}>();

const { add: toast } = useYikesToast();

// Local working copy — step actions refetch the page JSON and swap it.
const results = ref<Record<string, StepResult>>(props.results);

async function refresh(): Promise<void> {
    try {
        const fresh = await api.get<{ results: Record<string, StepResult> }>(window.location.href);
        results.value = fresh.results;
    } catch {
        toast({ severity: "error", summary: "Could not refresh the step results." });
    }
}

const stepUrl = computed(() => testingUrl(props.tester.slug, props.suite.slug, props.test.slug, "steps"));

const passedCount = computed(
    () => Object.values(results.value).filter((result) => result.status === "pass").length,
);
const hasRecords = computed(() => Object.keys(results.value).length > 0);
const allPassed = computed(() => passedCount.value === props.test.steps.length);

const recordingStep = ref<number | null>(null);

async function pass(step: number): Promise<void> {
    recordingStep.value = step;
    try {
        await api.post(stepUrl.value, { step, status: "pass" });
        await refresh();
    } catch {
        toast({ severity: "error", summary: "Could not record the step." });
    } finally {
        recordingStep.value = null;
    }
}

// Fail dialog — the reason is required and becomes a yikes note.
const showFailDialog = ref(false);
const failForm = useYikesForm<{ step: number; status: "fail"; reason: string }>({
    step: 0,
    status: "fail",
    reason: "",
});

function openFail(step: number): void {
    failForm.reset();
    failForm.clearErrors();
    failForm.data.step = step;
    showFailDialog.value = true;
}

function submitFail(): void {
    void failForm.submit("post", stepUrl.value, {
        onSuccess: (response) => {
            showFailDialog.value = false;
            toast({ severity: "success", summary: response.message ?? "Step recorded." });
            void refresh();
        },
    });
}

async function resetTest(): Promise<void> {
    try {
        await api.post(testingUrl(props.tester.slug, props.suite.slug, props.test.slug, "reset"));
        await refresh();
    } catch {
        toast({ severity: "error", summary: "Could not reset the test." });
    }
}
</script>

<template>
    <ChecklistShell
        :title="`${test.title} — ${tester.name}`"
        :crumbs="[
            { label: 'Testers', href: testingUrl() },
            { label: tester.name, href: testingUrl(tester.slug) },
            { label: suite.title, href: testingUrl(tester.slug, suite.slug) },
            { label: test.title },
        ]"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-surface-900 dark:text-surface-0">
                    {{ test.title }}
                </h1>
                <p
                    v-if="test.goal"
                    class="mt-1 text-sm text-surface-600 dark:text-surface-400"
                    v-html="checklistTextHtml(test.goal)"
                ></p>
            </div>
            <YButton v-if="hasRecords" variant="secondary" label="Reset test" size="sm" @click="resetTest" />
        </div>

        <YMessage v-if="allPassed" severity="success" class="mt-4"> All steps passed — this test is green. </YMessage>

        <p class="mt-4 text-xs font-semibold tracking-wide text-surface-500 uppercase dark:text-surface-400">
            {{ passedCount }}/{{ test.steps.length }} steps passed
        </p>

        <ol class="mt-2 space-y-3">
            <li
                v-for="(step, index) in test.steps"
                :key="index"
                class="rounded-md border bg-surface-0 p-4 shadow-sm dark:bg-surface-800"
                :class="{
                    'border-success-300 dark:border-success-700': results[String(index + 1)]?.status === 'pass',
                    'border-danger-300 dark:border-danger-700': results[String(index + 1)]?.status === 'fail',
                    'border-surface-200 dark:border-surface-700': !results[String(index + 1)],
                }"
            >
                <div class="flex items-start justify-between gap-4">
                    <div class="flex min-w-0 items-start gap-3">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-bold"
                            :class="{
                                'bg-success-100 text-success-700 dark:bg-success-900 dark:text-success-300':
                                    results[String(index + 1)]?.status === 'pass',
                                'bg-danger-100 text-danger-700 dark:bg-danger-900 dark:text-danger-300':
                                    results[String(index + 1)]?.status === 'fail',
                                'bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300':
                                    !results[String(index + 1)],
                            }"
                        >
                            <template v-if="results[String(index + 1)]?.status === 'pass'">✓</template>
                            <template v-else-if="results[String(index + 1)]?.status === 'fail'">✗</template>
                            <template v-else>{{ index + 1 }}</template>
                        </span>
                        <div class="min-w-0">
                            <p class="text-sm text-surface-800 dark:text-surface-200" v-html="checklistTextHtml(step)"></p>
                            <p
                                v-if="results[String(index + 1)]?.status === 'fail' && results[String(index + 1)]?.reason"
                                class="mt-1 text-sm text-danger-600 dark:text-danger-400"
                            >
                                {{ results[String(index + 1)].reason }}
                            </p>
                        </div>
                    </div>
                    <div class="flex shrink-0 gap-2">
                        <YButton
                            label="Pass"
                            size="sm"
                            :loading="recordingStep === index + 1"
                            :disabled="results[String(index + 1)]?.status === 'pass'"
                            @click="pass(index + 1)"
                        />
                        <YButton
                            variant="danger"
                            label="Fail"
                            size="sm"
                            :disabled="results[String(index + 1)]?.status === 'fail'"
                            @click="openFail(index + 1)"
                        />
                    </div>
                </div>
            </li>
        </ol>

        <p class="mt-6 text-xs text-surface-500 dark:text-surface-400">
            Spotted something outside these steps? Use the Yikes button on any page to file it with full page
            context.
        </p>

        <YDialog v-model:visible="showFailDialog" header="What went wrong?" width="sm:max-w-md">
            <form class="space-y-3" @submit.prevent="submitFail">
                <p class="text-sm text-surface-600 dark:text-surface-400">
                    Step {{ failForm.data.step }}: describe what you expected and what happened instead. This
                    files a yikes note for the dev queue.
                </p>
                <YTextarea v-model="failForm.data.reason" autofocus :rows="4" placeholder="Expected ... but ..." />
                <p v-if="failForm.errors.reason" class="text-sm text-danger-600 dark:text-danger-400">
                    {{ failForm.errors.reason }}
                </p>
                <div class="flex justify-end gap-2">
                    <YButton variant="secondary" label="Cancel" @click="showFailDialog = false" />
                    <YButton variant="danger" label="Mark failed" type="submit" :loading="failForm.processing" />
                </div>
            </form>
        </YDialog>
    </ChecklistShell>
</template>
