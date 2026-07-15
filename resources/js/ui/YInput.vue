<script setup lang="ts">
defineProps<{
    invalid?: boolean;
    placeholder?: string;
    id?: string;
}>();

const model = defineModel<string>({ default: "" });
</script>

<template>
    <input
        :id="id"
        v-model="model"
        type="text"
        :placeholder="placeholder"
        class="w-full rounded-md border bg-surface-0 px-3 py-2 text-sm text-surface-900 placeholder-surface-400 focus:outline-2 focus:-outline-offset-1 focus:outline-primary-500 dark:bg-surface-900 dark:text-surface-100 dark:placeholder-surface-500"
        :class="invalid ? 'border-danger-400 dark:border-danger-500' : 'border-surface-300 dark:border-surface-600'"
    />
</template>
