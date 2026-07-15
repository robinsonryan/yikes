<script setup lang="ts">
import { testingUrl } from "../../api";
import ChecklistShell from "../../components/ChecklistShell.vue";
import ChecklistStatusTag from "../../components/ChecklistStatusTag.vue";
import { checklistTextHtml, checklistTextPlain } from "../../utils/checklistText";
import type { SuiteSummary } from "../../types";

defineProps<{
    tester: { slug: string; name: string };
    suite: {
        slug: string;
        title: string;
        description: string | null;
        tests: { slug: string; title: string; goal: string | null; steps: string[] }[];
    };
    summary: SuiteSummary;
}>();
</script>

<template>
    <ChecklistShell
        :title="`${suite.title} — ${tester.name}`"
        :crumbs="[
            { label: 'Testers', href: testingUrl() },
            { label: tester.name, href: testingUrl(tester.slug) },
            { label: suite.title },
        ]"
    >
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold tracking-tight text-surface-900 dark:text-surface-0">
                    {{ suite.title }}
                </h1>
                <p
                    v-if="suite.description"
                    class="mt-1 text-sm text-surface-600 dark:text-surface-400"
                    v-html="checklistTextHtml(suite.description)"
                ></p>
            </div>
            <ChecklistStatusTag :status="summary.status" class="mt-2" />
        </div>

        <div class="mt-6 space-y-3">
            <a
                v-for="test in suite.tests"
                :key="test.slug"
                :href="testingUrl(tester.slug, suite.slug, test.slug)"
                class="flex items-center justify-between gap-4 rounded-md border border-surface-200 bg-surface-0 p-4 shadow-sm transition hover:border-primary-400 dark:border-surface-700 dark:bg-surface-800 dark:hover:border-primary-400"
            >
                <div class="min-w-0">
                    <p class="font-semibold text-surface-900 dark:text-surface-0">
                        {{ test.title }}
                    </p>
                    <p v-if="test.goal" class="mt-0.5 truncate text-sm text-surface-500 dark:text-surface-400">
                        {{ checklistTextPlain(test.goal) }}
                    </p>
                    <p class="mt-0.5 text-xs text-surface-500 dark:text-surface-400">
                        {{ summary.tests[test.slug]?.passed ?? 0 }}/{{ test.steps.length }} steps passed
                    </p>
                </div>
                <ChecklistStatusTag :status="summary.tests[test.slug]?.status ?? 'pending'" />
            </a>
        </div>
    </ChecklistShell>
</template>
