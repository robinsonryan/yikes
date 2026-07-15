<script setup lang="ts">
import YIcon from "./YIcon.vue";
import { toasts, useYikesToast } from "./toast";

const { dismiss } = useYikesToast();

const iconFor: Record<string, string> = {
    success: "check",
    error: "alert-circle",
    info: "alert-circle",
};
</script>

<template>
    <div class="pointer-events-none fixed top-4 right-4 z-[999999] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2">
        <div
            v-for="toast in toasts"
            :key="toast.id"
            class="pointer-events-auto flex items-start gap-2.5 rounded-md border p-3 text-sm shadow-lg"
            :class="{
                'border-success-200 bg-success-50 text-success-800 dark:border-success-800 dark:bg-success-950 dark:text-success-200':
                    toast.severity === 'success',
                'border-danger-200 bg-danger-50 text-danger-800 dark:border-danger-800 dark:bg-danger-950 dark:text-danger-200':
                    toast.severity === 'error',
                'border-info-200 bg-info-50 text-info-800 dark:border-info-800 dark:bg-info-950 dark:text-info-200':
                    toast.severity === 'info',
            }"
            role="status"
        >
            <YIcon :name="iconFor[toast.severity]" class="mt-0.5" />
            <div class="min-w-0 grow">
                <p class="font-semibold">{{ toast.summary }}</p>
                <p v-if="toast.detail" class="mt-0.5 opacity-90">{{ toast.detail }}</p>
            </div>
            <button type="button" class="cursor-pointer opacity-60 hover:opacity-100" aria-label="Dismiss" @click="dismiss(toast.id)">
                <YIcon name="x" />
            </button>
        </div>
    </div>
</template>
