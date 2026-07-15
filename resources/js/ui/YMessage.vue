<script setup lang="ts">
import { computed } from "vue";

const props = withDefaults(
    defineProps<{
        severity?: "info" | "success" | "error";
        size?: "sm" | "md";
    }>(),
    { severity: "info", size: "md" },
);

const classes = computed(() => {
    const bySeverity: Record<string, string> = {
        info: "bg-info-50 text-info-800 dark:bg-info-950/50 dark:text-info-300",
        success: "bg-success-50 text-success-800 dark:bg-success-950/50 dark:text-success-300",
        error: "bg-danger-50 text-danger-700 dark:bg-danger-950/50 dark:text-danger-300",
    };

    return [bySeverity[props.severity], props.size === "sm" ? "px-2 py-1 text-xs" : "px-3 py-2 text-sm"];
});
</script>

<template>
    <div class="rounded-md" :class="classes">
        <slot />
    </div>
</template>
