<script setup lang="ts">
import { testingUrl } from "../../api";
import ChecklistShell from "../../components/ChecklistShell.vue";

defineProps<{
    testers: { slug: string; name: string }[];
}>();
</script>

<template>
    <ChecklistShell title="Test Checklists" :crumbs="[{ label: 'Testers' }]">
        <h1 class="text-3xl font-bold tracking-tight text-surface-900 dark:text-surface-0">Who's testing?</h1>
        <p class="mt-1 text-sm text-surface-600 dark:text-surface-400">
            Pick your name to see your credentials and checklists.
        </p>

        <div class="mt-6 grid gap-3 sm:grid-cols-2">
            <a
                v-for="tester in testers"
                :key="tester.slug"
                :href="testingUrl(tester.slug)"
                class="rounded-md border border-surface-200 bg-surface-0 p-5 text-lg font-semibold text-surface-900 shadow-sm transition hover:border-primary-400 hover:text-primary-600 dark:border-surface-700 dark:bg-surface-800 dark:text-surface-0 dark:hover:border-primary-400 dark:hover:text-primary-400"
            >
                {{ tester.name }}
            </a>
        </div>

        <p v-if="testers.length === 0" class="mt-6 text-sm text-surface-500 dark:text-surface-400">
            No testers defined yet — add them to <code>resources/checklists/testers.yaml</code>.
        </p>
    </ChecklistShell>
</template>
