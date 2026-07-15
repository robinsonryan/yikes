<script setup lang="ts">
import { computed } from "vue";
import type { ChecklistStatus } from "../types";
import YTag from "../ui/YTag.vue";

const props = defineProps<{ status: ChecklistStatus }>();

const display = computed(() => {
    switch (props.status) {
        case "passed":
            return { label: "✓ Passed", severity: "success" as const };
        case "failed":
            return { label: "✗ Failed", severity: "danger" as const };
        case "in-progress":
            return { label: "In progress", severity: "info" as const };
        default:
            return { label: "Not started", severity: "secondary" as const };
    }
});
</script>

<template>
    <YTag :value="display.label" :severity="display.severity" class="shrink-0" />
</template>
