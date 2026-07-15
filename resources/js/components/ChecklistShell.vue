<script setup lang="ts">
import { computed, watchEffect } from "vue";
import { bootstrap } from "../bootstrap";
import YIcon from "../ui/YIcon.vue";

const props = defineProps<{
    title: string;
    /** Breadcrumb trail; the last entry renders as plain text. */
    crumbs?: { label: string; href?: string }[];
}>();

const appName = bootstrap().name;

watchEffect(() => {
    document.title = props.title;
});

/**
 * The nearest linked ancestor — rendered as a prominent "Back to {parent}"
 * button above the page content (the header crumbs are easy to miss).
 */
const backCrumb = computed(() => {
    const linked = (props.crumbs ?? []).filter(
        (crumb): crumb is { label: string; href: string } => crumb.href !== undefined,
    );

    return linked.length > 0 ? linked[linked.length - 1] : null;
});
</script>

<template>
    <div class="min-h-screen bg-surface-100 font-sans text-surface-900 dark:bg-surface-900 dark:text-surface-100">
        <header class="border-b border-surface-200 bg-surface-0 dark:border-surface-700 dark:bg-surface-800">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-4 sm:px-6">
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary-500 text-sm font-bold text-white dark:bg-primary-400 dark:text-surface-900"
                >
                    ✓
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold tracking-wide text-surface-500 uppercase dark:text-surface-400">
                        {{ appName }} test checklists
                    </p>
                    <nav v-if="crumbs?.length" class="flex min-w-0 items-center gap-1 text-sm">
                        <template v-for="(crumb, index) in crumbs" :key="index">
                            <span v-if="index > 0" class="text-surface-400 dark:text-surface-500">/</span>
                            <a
                                v-if="crumb.href"
                                :href="crumb.href"
                                class="truncate text-primary-600 hover:underline dark:text-primary-400"
                            >
                                {{ crumb.label }}
                            </a>
                            <span v-else class="truncate font-medium text-surface-900 dark:text-surface-0">
                                {{ crumb.label }}
                            </span>
                        </template>
                    </nav>
                </div>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6 sm:px-6">
            <a
                v-if="backCrumb"
                :href="backCrumb.href"
                class="mb-4 inline-flex items-center gap-2 rounded-md border border-surface-200 bg-surface-0 px-3 py-1.5 text-sm font-medium text-surface-700 shadow-sm transition hover:border-primary-400 hover:text-primary-600 dark:border-surface-700 dark:bg-surface-800 dark:text-surface-300 dark:hover:border-primary-400 dark:hover:text-primary-400"
            >
                <YIcon name="arrow-left" />
                Back to {{ backCrumb.label }}
            </a>

            <slot />
        </main>
    </div>
</template>
