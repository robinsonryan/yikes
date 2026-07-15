<script setup lang="ts">
import { computed } from "vue";
import YIcon from "./YIcon.vue";

/**
 * The island's only button. `text` renders the borderless/ghost treatment of
 * the same variant (icon-row actions); `rounded` + no label = circular
 * icon button (the FAB actions).
 */
const props = withDefaults(
    defineProps<{
        label?: string;
        icon?: string;
        variant?: "primary" | "secondary" | "danger";
        size?: "sm" | "md";
        text?: boolean;
        rounded?: boolean;
        loading?: boolean;
        disabled?: boolean;
        type?: "button" | "submit";
    }>(),
    { variant: "primary", size: "md", type: "button" },
);

const classes = computed(() => {
    const solid: Record<string, string> = {
        primary:
            "bg-primary-600 text-white hover:bg-primary-700 dark:bg-primary-400 dark:text-surface-950 dark:hover:bg-primary-300",
        secondary:
            "bg-surface-700 text-white hover:bg-surface-800 dark:bg-surface-600 dark:text-surface-0 dark:hover:bg-surface-500",
        danger: "bg-danger-600 text-white hover:bg-danger-700 dark:bg-danger-500 dark:hover:bg-danger-400",
    };
    const ghost: Record<string, string> = {
        primary: "text-primary-600 hover:bg-primary-50 dark:text-primary-400 dark:hover:bg-primary-950/40",
        secondary: "text-surface-500 hover:bg-surface-100 dark:text-surface-400 dark:hover:bg-surface-700/40",
        danger: "text-danger-600 hover:bg-danger-50 dark:text-danger-400 dark:hover:bg-danger-950/40",
    };

    return [
        props.text ? ghost[props.variant] : solid[props.variant],
        props.rounded ? "rounded-full" : "rounded-md",
        props.size === "sm" ? "px-2.5 py-1.5 text-xs" : "px-3.5 py-2 text-sm",
        !props.label ? (props.size === "sm" ? "p-1.5!" : "p-2.5!") : "",
    ];
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        class="inline-flex cursor-pointer items-center justify-center gap-1.5 font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 disabled:cursor-not-allowed disabled:opacity-60"
        :class="classes"
    >
        <YIcon v-if="loading" name="" spin />
        <YIcon v-else-if="icon" :name="icon" />
        <span v-if="label">{{ label }}</span>
        <slot />
    </button>
</template>
