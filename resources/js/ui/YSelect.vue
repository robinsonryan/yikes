<script setup lang="ts">
defineProps<{
    options: { label: string; value: string }[];
    size?: "sm" | "md";
    disabled?: boolean;
    id?: string;
    ariaLabel?: string;
}>();

const model = defineModel<string>({ default: "" });
</script>

<template>
    <select
        :id="id"
        v-model="model"
        :disabled="disabled"
        :aria-label="ariaLabel"
        class="w-full appearance-none rounded-md border border-surface-300 bg-surface-0 bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22%2394a3b8%22%20stroke-width%3D%222%22%20stroke-linecap%3D%22round%22%3E%3Cpath%20d%3D%22m6%209%206%206%206-6%22%2F%3E%3C%2Fsvg%3E')] bg-[length:1em] bg-[position:right_0.5rem_center] bg-no-repeat pr-8 text-surface-900 disabled:opacity-60 focus:outline-2 focus:-outline-offset-1 focus:outline-primary-500 dark:border-surface-600 dark:bg-surface-900 dark:text-surface-100"
        :class="size === 'sm' ? 'px-2 py-1 text-xs' : 'px-3 py-2 text-sm'"
    >
        <option v-for="option in options" :key="option.value" :value="option.value">
            {{ option.label }}
        </option>
    </select>
</template>
