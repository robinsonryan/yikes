<script setup lang="ts">
defineProps<{
    inputId?: string;
    ariaLabel?: string;
}>();

const model = defineModel<boolean>({ default: false });
</script>

<template>
    <input
        :id="inputId"
        v-model="model"
        type="checkbox"
        :aria-label="ariaLabel"
        class="size-4 cursor-pointer appearance-none rounded-sm border border-surface-300 bg-surface-0 checked:border-primary-600 checked:bg-primary-600 checked:bg-[url('data:image/svg+xml;charset=utf-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20viewBox%3D%220%200%2024%2024%22%20fill%3D%22none%22%20stroke%3D%22white%22%20stroke-width%3D%223%22%20stroke-linecap%3D%22round%22%20stroke-linejoin%3D%22round%22%3E%3Cpath%20d%3D%22m5%2013%204%204L19%207%22%2F%3E%3C%2Fsvg%3E')] bg-center bg-no-repeat focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 dark:border-surface-600 dark:bg-surface-900 dark:checked:bg-primary-500 dark:checked:border-primary-500"
    />
</template>
