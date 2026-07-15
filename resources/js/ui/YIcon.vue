<script setup lang="ts">
import { computed } from "vue";
import { ICON_PATHS } from "./icons";

const props = defineProps<{
    name: string;
    /** true = spinner animation (used by button loading states). */
    spin?: boolean;
}>();

const path = computed(() => ICON_PATHS[props.name] ?? "");
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="inline-block size-[1.1em] shrink-0 align-[-0.18em]"
        :class="{ 'animate-spin': spin }"
        aria-hidden="true"
    >
        <template v-if="spin">
            <circle cx="12" cy="12" r="9" class="opacity-25" />
            <path d="M21 12a9 9 0 0 0-9-9" />
        </template>
        <path v-else :d="path" />
    </svg>
</template>
