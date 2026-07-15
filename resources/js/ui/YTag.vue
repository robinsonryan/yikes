<script setup lang="ts">
import { computed } from "vue";

const props = defineProps<{
    value: string;
    severity: "success" | "danger" | "info" | "secondary";
}>();

const classes = computed(() => {
    const bySeverity: Record<string, string> = {
        success: "bg-success-100 text-success-700 dark:bg-success-900/50 dark:text-success-300",
        danger: "bg-danger-100 text-danger-700 dark:bg-danger-900/50 dark:text-danger-300",
        info: "bg-info-100 text-info-700 dark:bg-info-900/50 dark:text-info-300",
        secondary: "bg-surface-100 text-surface-600 dark:bg-surface-700 dark:text-surface-300",
    };

    return bySeverity[props.severity];
});
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold whitespace-nowrap" :class="classes">
        {{ value }}
    </span>
</template>
